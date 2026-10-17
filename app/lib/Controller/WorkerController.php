<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Controller;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\FileGone;
use OCA\BuscadorDocumental\Security\WorkerAuth;
use OCA\BuscadorDocumental\Security\WorkerAuthException;
use OCA\BuscadorDocumental\Worker\ClaimGuard;
use OCA\BuscadorDocumental\Worker\NodeLocator;
use OCA\BuscadorDocumental\Worker\RangeStreamResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The worker channel, v1 (openapi/worker-v1.yaml). Not a user API: every request is HMAC-authenticated and only served
 * while the calling worker holds the current claim on the file. Failures return an empty JSON object; the reason goes
 * to the log as a code, never with payload, paths or owners.
 */
class WorkerController extends Controller {
	public function __construct(
		IRequest $request,
		private WorkerAuth $auth,
		private ClaimGuard $claims,
		private NodeLocator $nodes,
		private ExclusionChecker $exclusion,
		private LoggerInterface $logger,
	) {
		parent::__construct('buscador_documental', $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function meta(int $id): Response {
		$file = $this->guard($id);
		if ($file instanceof Response) {
			return $file;
		}
		return new JSONResponse([
			'file_id' => $id,
			'etag' => $file->getEtag(),
			'size' => $file->getSize(),
			'mime' => $file->getMimeType(),
			'name' => $file->getName(),
			'mtime' => $file->getMTime(),
			'encrypted' => $file->isEncrypted(),
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function content(int $id): Response {
		$file = $this->guard($id);
		if ($file instanceof Response) {
			return $file;
		}
		$ifMatch = trim($this->request->getHeader('If-Match'), '" ');
		if ($ifMatch === '') {
			return new JSONResponse([], Http::STATUS_PRECONDITION_REQUIRED);
		}
		if ($ifMatch !== $file->getEtag()) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}
		$size = (int)$file->getSize();
		$range = $this->request->getHeader('Range');
		$start = 0;
		$length = $size;
		$status = Http::STATUS_OK;
		$headers = ['Content-Type' => 'application/octet-stream', 'Accept-Ranges' => 'bytes', 'ETag' => '"' . $file->getEtag() . '"'];
		if ($range !== '') {
			$parsed = self::parseRange($range, $size);
			if ($parsed === null) {
				return new JSONResponse([], 416, ['Content-Range' => "bytes */$size"]);
			}
			[$start, $end] = $parsed;
			$length = $end - $start + 1;
			$status = Http::STATUS_PARTIAL_CONTENT;
			$headers['Content-Range'] = "bytes $start-$end/$size";
		}
		$headers['Content-Length'] = (string)$length;
		$stream = $file->fopen('r');
		if (!is_resource($stream)) {
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new RangeStreamResponse($stream, $start, $length, $status, $headers);
	}

	/** @return array{0:int,1:int}|null inclusive byte range, null when unsatisfiable or malformed */
	public static function parseRange(string $header, int $size): ?array {
		if ($size <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
			return null;
		}
		if ($m[1] === '') {
			$n = (int)$m[2];
			return $n === 0 ? null : [max(0, $size - $n), $size - 1];
		}
		$start = (int)$m[1];
		$end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
		return $start > $end || $start >= $size ? null : [$start, $end];
	}

	private function guard(int $id): File|Response {
		$deny = fn (string $why, int $status): Response => $this->deny($why, $status);
		$uri = $this->request->getRequestUri();
		$jobId = $this->request->getHeader('X-BD-Job-Id');
		$epoch = $this->request->getHeader('X-BD-Lease-Epoch');
		try {
			$workerId = $this->auth->authenticate([
				'method' => $this->request->getMethod(),
				'path' => $this->request->getRawPathInfo(),
				'query' => (string)parse_url($uri, PHP_URL_QUERY),
				'range' => $this->request->getHeader('Range'),
				'ifMatch' => $this->request->getHeader('If-Match'),
				'body' => (string)file_get_contents('php://input'),
				'authorization' => $this->request->getHeader('Authorization'),
				'timestamp' => $this->request->getHeader('X-BD-Timestamp'),
				'nonce' => $this->request->getHeader('X-BD-Nonce'),
				'jobId' => $jobId,
				'epoch' => $epoch,
			]);
		} catch (WorkerAuthException $e) {
			return $deny('auth:' . $e->reason, Http::STATUS_UNAUTHORIZED);
		}
		if (!ctype_digit($jobId) || !ctype_digit($epoch)) {
			return $deny('claim:malformed', Http::STATUS_FORBIDDEN);
		}
		if ($this->claims->isPaused()) {
			return $deny('paused', Http::STATUS_FORBIDDEN);
		}
		if ($this->claims->overCap($workerId)) {
			return $deny('claim:over_cap', Http::STATUS_TOO_MANY_REQUESTS);
		}
		if ($this->claims->activeClaim($workerId, (int)$jobId, (int)$epoch, $id) === null) {
			return $deny('claim:none', Http::STATUS_FORBIDDEN);
		}
		try {
			if ($this->exclusion->isExcluded($id)) {
				return $deny('excluded', Http::STATUS_FORBIDDEN);
			}
		} catch (FileGone) {
			return $deny('not_found', Http::STATUS_NOT_FOUND);
		} catch (ExclusionUnavailable) {
			return $deny('exclusion_unavailable', Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$file = $this->nodes->locate($id);
		return $file ?? $deny('not_found', Http::STATUS_NOT_FOUND);
	}

	private function deny(string $why, int $status): Response {
		$this->logger->warning('worker channel refused request', ['app' => 'buscador_documental', 'reason' => $why, 'status' => $status]);
		return new JSONResponse([], $status);
	}
}
