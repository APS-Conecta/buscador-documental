<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Controller;

use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Ops\Doctor;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Status\StatusReport;
use OCA\BuscadorDocumental\Ui\AdminActions;
use OCA\BuscadorDocumental\Ui\FolderList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Administration endpoints. No #[NoAdminRequired]: Nextcloud refuses non-administrators before the method runs, and CSRF
 * protection stays on. Nothing here returns a file name, a path or any text of a document except through AdminActions::skipped,
 * which shows a name only for files the administrator could open anyway.
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private StatusReport $status,
		private Doctor $doctor,
		private ScopeRules $rules,
		private AdminActions $actions,
		private FolderList $folders,
	) {
		parent::__construct('buscador_documental', $request);
	}

	public function overview(): JSONResponse {
		return $this->json([
			'status' => $this->status->snapshot(),
			'checks' => $this->doctor->run(),
			'rules' => $this->rulesWithPaths(),
			'skipped' => $this->actions->skippedCounts(),
		]);
	}

	public function skipped(?string $reason = null, int $offset = 0, int $limit = 50): JSONResponse {
		try {
			return $this->json($this->actions->skipped($reason, $offset, $limit, $this->uid()));
		} catch (ExclusionUnavailable) {
			return $this->json(['status' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	public function pause(): JSONResponse {
		$this->actions->pause($this->uid());
		return $this->json(['paused' => true]);
	}

	public function resume(): JSONResponse {
		$this->actions->resume($this->uid());
		return $this->json(['paused' => false]);
	}

	public function acknowledgeNotice(): JSONResponse {
		$this->actions->acknowledgeNotice($this->uid());
		return $this->json(['ok' => true]);
	}

	public function removeRule(int $id): JSONResponse {
		return $this->actions->excludeRule($id, $this->uid()) ? $this->json(['ok' => true]) : $this->json(['ok' => false], Http::STATUS_NOT_FOUND);
	}

	public function retry(int $id): JSONResponse {
		return $this->actions->retry($id, $this->uid()) ? $this->json(['ok' => true]) : $this->json(['ok' => false], Http::STATUS_NOT_FOUND);
	}

	/** The rules, each with the folder's path when this administrator can read the folder (otherwise null: no name is revealed). */
	private function rulesWithPaths(): array {
		try {
			$paths = array_column($this->folders->forUser($this->uid()), 'path', 'id');
		} catch (ExclusionUnavailable) {
			$paths = [];                                       // the overview must still load when the exclusion state does not: that is when it is needed
		}
		return array_map(static fn (array $r): array => $r + ['path' => $r['file_id'] === null ? null : ($paths[$r['file_id']] ?? null)], $this->rules->all());
	}

	private function uid(): string {
		return $this->session->getUser()?->getUID() ?? '';
	}

	/** @param array<string, mixed> $data */
	private function json(array $data, int $status = Http::STATUS_OK): JSONResponse {
		$r = new JSONResponse($data, $status);
		$r->addHeader('Cache-Control', 'no-store');
		return $r;
	}
}
