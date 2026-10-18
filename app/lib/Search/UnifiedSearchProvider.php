<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Throwable;

/**
 * The Nextcloud search bar: the five best documents by words (Exacta), through the same Searcher as the page, so the same live
 * permission recheck applies. Hidden while the index is paused or has no folders, so it never promises what it cannot find.
 */
class UnifiedSearchProvider implements IProvider {
	public const LIMIT = 5;

	public function __construct(private Searcher $searcher, private Settings $settings, private ScopeRules $rules, private IL10N $l, private IURLGenerator $urls) {
	}

	public function getId(): string {
		return 'buscador_documental';
	}

	public function getName(): string {
		return $this->l->t('Documentos (contenido)');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		try {
			if ($this->settings->isPaused() || $this->rules->all() === []) {
				return null;
			}
		} catch (Throwable) {
			return null;                                   // an unavailable index shows nothing rather than an error in the search bar
		}
		return str_starts_with($route, 'buscador_documental.') ? -1 : 55;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if (mb_strlen($term) < 2 || mb_strlen($term) > SearchRequest::MAX_QUERY_CHARS) {
			return SearchResult::complete($this->getName(), []);
		}
		try {
			$result = $this->searcher->search($user->getUID(), SearchRequest::fromArray(['query' => $term, 'mode' => 'exacta', 'page_size' => 25]));
		} catch (Throwable) {
			return SearchResult::complete($this->getName(), []);
		}
		if (($result['status'] ?? '') !== 'ok') {
			return SearchResult::complete($this->getName(), []);
		}
		$entries = [];
		foreach (array_slice($result['hits'] ?? [], 0, min(self::LIMIT, max(1, $query->getLimit()))) as $hit) {
			$entries[] = new SearchResultEntry(
				'',
				(string)$hit['title'],
				(string)($hit['snippet'] ?? ''),
				$this->urls->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => (int)$hit['file_id']]),
				'icon-search',
			);
		}
		return SearchResult::complete($this->getName(), $entries);
	}
}
