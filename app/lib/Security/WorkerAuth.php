<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Security;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use OCP\IMemcache;

/**
 * Bearer + HMAC authentication of the worker channel (S1 design section 4, D3, M6).
 *
 *   Authorization: Bearer <worker_id>.<hex hmac-sha256>
 *   X-BD-Timestamp, X-BD-Nonce, X-BD-Job-Id, X-BD-Lease-Epoch
 *   signed string = method, raw path, query string, Range, If-Match, worker_id, sha256(body),
 *                   timestamp, nonce, job id, lease epoch   (joined by "\n"; absent values are empty)
 *
 * The nonce is stored (atomic IMemcache::add) only after the MAC verified, so unauthenticated callers cannot fill the
 * cache. The cache must keep entries for the whole TTL; if no real cache exists the channel fails closed.
 */
class WorkerAuth {
	public const WINDOW_SECONDS = 120;
	public const NONCE_TTL_SECONDS = 300;
	public const APP_ID = 'buscador_documental';

	public function __construct(
		private IAppConfig $config,
		private ICacheFactory $caches,
		private ITimeFactory $time,
	) {
	}

	public static function signedString(
		string $method, string $path, string $query, string $range, string $ifMatch,
		string $workerId, string $body, int $timestamp, string $nonce, string $jobId, string $epoch,
	): string {
		return implode("\n", [
			strtoupper($method), $path, $query, $range, $ifMatch, $workerId,
			hash('sha256', $body), (string)$timestamp, $nonce, $jobId, $epoch,
		]);
	}

	public static function sign(string $secret, string $signedString): string {
		return hash_hmac('sha256', $signedString, $secret);
	}

	/**
	 * @param array{method:string, path:string, query:string, range:string, ifMatch:string, body:string,
	 *              authorization:string, timestamp:string, nonce:string, jobId:string, epoch:string} $r
	 * @return string the authenticated worker id
	 * @throws WorkerAuthException
	 */
	public function authenticate(array $r): string {
		if (!preg_match('/^Bearer ([A-Za-z0-9_-]{1,64})\.([0-9a-f]{64})$/', $r['authorization'], $m)) {
			throw new WorkerAuthException('malformed_authorization');
		}
		[, $workerId, $mac] = $m;
		if (!preg_match('/^[0-9]{1,12}$/', $r['timestamp']) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $r['nonce'])) {
			throw new WorkerAuthException('malformed_headers');
		}
		$ts = (int)$r['timestamp'];
		if (abs($this->time->getTime() - $ts) > self::WINDOW_SECONDS) {
			throw new WorkerAuthException('timestamp_out_of_window');
		}
		$secret = $this->config->getValueString(self::APP_ID, 'worker_secret', '');
		if (strlen($secret) < 32) {
			throw new WorkerAuthException('secret_not_configured');
		}
		$signed = self::signedString(
			$r['method'], $r['path'], $r['query'], $r['range'], $r['ifMatch'],
			$workerId, $r['body'], $ts, $r['nonce'], $r['jobId'], $r['epoch'],
		);
		$ok = hash_equals(self::sign($secret, $signed), $mac);
		if (!$ok) {
			// After a rotation the previous secret still works until its expiry, so a running worker is not cut off (ADR-0015).
			$prev = $this->config->getValueString(self::APP_ID, 'worker_secret_prev', '');
			$until = $this->config->getValueString(self::APP_ID, 'worker_secret_prev_until', '');
			if (strlen($prev) >= 32 && preg_match('/^[0-9]{1,12}$/', $until) === 1 && (int)$until > $this->time->getTime()) {
				$ok = hash_equals(self::sign($prev, $signed), $mac);
			}
		}
		if (!$ok) {
			throw new WorkerAuthException('bad_mac');
		}
		$this->rememberNonce($workerId, $r['nonce']);
		return $workerId;
	}

	private function rememberNonce(string $workerId, string $nonce): void {
		if (!$this->caches->isAvailable()) {
			throw new WorkerAuthException('no_cache_available');
		}
		$cache = $this->caches->createDistributed('bd_worker_nonce');
		if (!$cache instanceof IMemcache) {
			throw new WorkerAuthException('cache_has_no_atomic_add');
		}
		if (!$cache->add($workerId . ':' . $nonce, 1, self::NONCE_TTL_SECONDS)) {
			throw new WorkerAuthException('nonce_replayed');
		}
	}
}
