<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Controller;

use OCA\BuscadorDocumental\Search\InvalidSearch;
use OCA\BuscadorDocumental\Search\Searcher;
use OCA\BuscadorDocumental\Search\SearchRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The search API for logged-in users. The user is always the session user: no parameter can name another one, and only the
 * documented parameters are read. Responses are never cached (they carry cursors and document snippets).
 */
class SearchController extends Controller {
	private const PARAMS = ['query', 'mode', 'sort', 'page_size', 'cursor', 'filters'];
	private const HTTP = ['ok' => Http::STATUS_OK, 'too_many' => Http::STATUS_OK, 'cursor_expired' => Http::STATUS_GONE,
		'invalid_cursor' => Http::STATUS_BAD_REQUEST, 'unavailable' => Http::STATUS_SERVICE_UNAVAILABLE];

	public function __construct(IRequest $request, private Searcher $searcher, private IUserSession $session) {
		parent::__construct('buscador_documental', $request);
	}

	#[NoAdminRequired]
	public function search(): JSONResponse {
		$uid = $this->session->getUser()?->getUID();
		if ($uid === null) {
			return $this->json(['status' => 'unauthorized', 'message' => '', 'hits' => []], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$request = SearchRequest::fromArray(array_intersect_key($this->request->getParams(), array_flip(self::PARAMS)));
		} catch (InvalidSearch $e) {
			return $this->json(['status' => 'invalid', 'message' => $e->getMessage(), 'hits' => []], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$result = $this->searcher->search($uid, $request);
		return $this->json($result, self::HTTP[$result['status']] ?? Http::STATUS_SERVICE_UNAVAILABLE);
	}

	#[NoAdminRequired]
	public function modes(): JSONResponse {
		if ($this->session->getUser() === null) {
			return $this->json(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}
		return $this->json(['modes' => $this->searcher->modes()], Http::STATUS_OK);
	}

	/** @param array<string, mixed> $data */
	private function json(array $data, int $status): JSONResponse {
		$response = new JSONResponse($data, $status);
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}
}
