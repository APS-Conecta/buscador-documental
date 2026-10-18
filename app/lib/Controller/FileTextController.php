<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Controller;

use OCA\BuscadorDocumental\Ui\FileText;
use OCA\BuscadorDocumental\Ui\FolderList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/** Recognised text of one file for the Files sidebar tab. "Not found" is the answer for unreadable, excluded and unknown files alike. */
class FileTextController extends Controller {
	public function __construct(IRequest $request, private IUserSession $session, private FileText $text, private FolderList $folders) {
		parent::__construct('buscador_documental', $request);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return $this->json(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}
		$out = $this->text->text($uid, $id);
		return $out === null ? $this->json(['status' => 'not_found'], Http::STATUS_NOT_FOUND) : $this->json($out);
	}

	#[NoAdminRequired]
	public function folders(): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		return $uid === null ? $this->json(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED) : $this->json(['folders' => $this->folders->forUser($uid)]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function reread(int $id): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return $this->json(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}
		return $this->text->reread($uid, $id) === 'queued' ? $this->json(['status' => 'queued']) : $this->json(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
	}

	/** @param array<string, mixed> $data */
	private function json(array $data, int $status = Http::STATUS_OK): JSONResponse {
		$r = new JSONResponse($data, $status);
		$r->addHeader('Cache-Control', 'no-store');
		return $r;
	}
}
