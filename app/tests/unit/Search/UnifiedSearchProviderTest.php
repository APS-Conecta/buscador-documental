<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Search\Searcher;
use OCA\BuscadorDocumental\Search\SearchRequest;
use OCA\BuscadorDocumental\Search\UnifiedSearchProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

class UnifiedSearchProviderTest extends TestCase {
	private function provider(array $result, bool $paused = false, array $rules = [['id' => 1, 'kind' => 'folder', 'file_id' => 5]], ?SearchRequest &$seen = null): UnifiedSearchProvider {
		$searcher = $this->createMock(Searcher::class);
		$searcher->method('search')->willReturnCallback(function (string $u, SearchRequest $r) use ($result, &$seen) {
			$seen = $r;
			return $result;
		});
		$scope = $this->createMock(ScopeRules::class);
		$scope->method('all')->willReturn($rules);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $r, array $p): string => "https://nc/f/{$p['fileid']}");
		return new UnifiedSearchProvider($searcher, $scope, $l, $urls);
	}

	private function query(string $term, int $limit = 5): ISearchQuery {
		$q = $this->createMock(ISearchQuery::class);
		$q->method('getTerm')->willReturn($term);
		$q->method('getLimit')->willReturn($limit);
		return $q;
	}

	private function user(): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn('bea');
		return $u;
	}

	private function hits(int $n): array {
		return ['status' => 'ok', 'hits' => array_map(static fn (int $i): array => ['file_id' => $i, 'title' => "Doc $i", 'snippet' => "texto $i"], range(1, $n))];
	}

	public function testItShowsAtMostFiveHitsFromTheKeywordSearchAsTheSessionUser(): void {
		$seen = null;
		$r = $this->provider($this->hits(9), seen: $seen)->search($this->user(), $this->query('glucosa'))->jsonSerialize();
		$this->assertCount(5, $r['entries']);
		$this->assertSame('exacta', $seen->mode);
		$this->assertSame('https://nc/f/1', $r['entries'][0]->jsonSerialize()['resourceUrl']);
	}

	public function testTheSublineIsPlainText(): void {
		$hits = ['status' => 'ok', 'hits' => [['file_id' => 1, 'title' => 'T', 'snippet' => 'Tom &amp; Jerry <mark>glucosa</mark> &lt;b&gt;']]];
		$e = $this->provider($hits)->search($this->user(), $this->query('glucosa'))->jsonSerialize()['entries'][0]->jsonSerialize();
		$this->assertSame('Tom & Jerry glucosa <b>', $e['line1'] ?? $e['subline'] ?? null);
	}

	public function testShortOrEmptyTermsAndFailuresGiveNothing(): void {
		$this->assertSame([], $this->provider($this->hits(3))->search($this->user(), $this->query('a'))->jsonSerialize()['entries']);
		$this->assertSame([], $this->provider(['status' => 'unavailable', 'hits' => []])->search($this->user(), $this->query('glucosa'))->jsonSerialize()['entries']);
	}

	public function testItHidesWithoutFoldersButNotWhilePaused(): void {
		$this->assertNotNull($this->provider([], paused: true)->getOrder('files.view.index', []), 'what is already read stays searchable');
		$this->assertNull($this->provider([], rules: [])->getOrder('files.view.index', []));
		$this->assertNotNull($this->provider([])->getOrder('files.view.index', []));
	}
}
