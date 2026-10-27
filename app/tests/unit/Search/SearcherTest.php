<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\Cursor;
use OCA\BuscadorDocumental\Search\EmbedClient;
use OCA\BuscadorDocumental\Search\Filters;
use OCA\BuscadorDocumental\Search\Glossary;
use OCA\BuscadorDocumental\Search\HitBuilder;
use OCA\BuscadorDocumental\Search\Searcher;
use OCA\BuscadorDocumental\Search\SearchRequest;
use OCA\BuscadorDocumental\Search\SnapshotStore;
use OCA\BuscadorDocumental\Search\VectorState;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\ScriptedRecheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SearcherTest extends TestCase {
	private const SECRET = 'instance-secret-0123456789';
	/** @var array<string, array{file_ids: list<int>, chunk_ids: list<int>, capped: bool}> */
	private array $snapshots = [];
	/** @var array<int, array{name: string, mtime: int}> */
	private array $meta = [];
	/** @var list<array<string, mixed>> */
	private array $keywordRows = [];
	/** whether the keyword leg reports a full candidate window (older matches were not ranked) */
	private bool $windowFull = false;
	/** @var list<array<string, mixed>> */
	private array $vectorRows = [];
	/** @var list<array{string, mixed}> */
	private array $seen = [];
	private bool $semantic = true;
	private ?array $queryVector = [1.0, 0.0, 0.0];
	private int $accessible = 50;
	private bool $ann = true;
	/** @var array<int, list<Node>|\Throwable> */
	private array $nodes = [];
	/** @var array<string, list<string>> object id => tag ids (tag 3 is rag-excluded) */
	private array $tagged = [];

	private function node(string $name, string $etag = 'e1'): Node {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn(true);
		$n->method('getName')->willReturn($name);
		$n->method('getEtag')->willReturn($etag);
		return $n;
	}

	/** Files 1..$n, all readable; keyword and vector legs return them in id order unless a test sets rows. */
	private function corpus(int $n): void {
		for ($i = 1; $i <= $n; $i++) {
			$this->nodes[$i] = [$this->node("f$i.pdf")];
			$this->meta[$i] = ['name' => "f$i.pdf", 'mtime' => 1000 + $i];
			$this->keywordRows[] = ['file_id' => $i, 'chunk_id' => $i * 10];
		}
	}

	/** The ranked list is exactly these file ids, in this order; the refused ones are stored for the caller but no longer readable. */
	private function rankedList(array $ids, array $refused = []): void {
		$this->keywordRows = [];
		$this->nodes = [];
		$this->meta = [];
		$refused = array_flip($refused);
		foreach ($ids as $i) {
			$this->keywordRows[] = ['file_id' => $i, 'chunk_id' => $i * 10];
			$this->nodes[$i] = isset($refused[$i]) ? [] : [$this->node("f$i.pdf")];
			$this->meta[$i] = ['name' => "f$i.pdf", 'mtime' => 1000 + $i];
		}
	}

	private function searcher(?LoggerInterface $log = null): Searcher {
		$db = $this->createMock(IndexDb::class);
		$db->method('withTimeout')->willReturnCallback(static fn (int $ms, callable $work) => $work());
		$db->method('rows')->willReturnCallback(function (string $sql) {
			if (str_contains($sql, 'aps_search.file_meta')) {
				return array_map(fn (int $id): array => ['file_id' => $id, 'name' => $this->meta[$id]['name'], 'mtime' => $this->meta[$id]['mtime']], array_keys($this->meta));
			}
			if (str_contains($sql, 'aps_search.file_state')) {
				return array_map(static fn (int $id): array => ['file_id' => $id, 'etag' => 'e1'], array_keys($this->meta));
			}
			return [];
		});
		$principals = $this->createMock(PrincipalResolver::class);
		$principals->method('forUser')->willReturn(['u:b', 'x:recheck']);

		/** @var CandidateQuery&MockObject $cand */
		$cand = $this->createMock(CandidateQuery::class);
		$cand->method('exacta')->willReturnCallback(function (string $q, array $pairs, string $set, Filters $f, int $limit, ?bool $relax = null) {
			$this->seen[] = ['exacta', [$q, $pairs, $f, $limit, $relax]];
			return [array_slice($this->keywordRows, 0, $limit), $this->windowFull];
		});
		$cand->method('significado')->willReturnCallback(function (array $v, int $gen, string $set, Filters $f, int $limit, bool $exact) {
			$this->seen[] = ['significado', [$gen, $f, $limit, $exact]];
			return array_slice($this->vectorRows, 0, $limit);
		});
		$cand->method('accessibleFiles')->willReturnCallback(fn () => $this->accessible);
		$cand->method('hasAnnIndex')->willReturnCallback(fn () => $this->ann);

		$state = $this->createMock(VectorState::class);
		$state->method('availability')->willReturnCallback(fn () => $this->semantic
			? ['semantic' => true, 'reason' => null, 'coverage' => 0.99, 'gen' => 1]
			: ['semantic' => false, 'reason' => 'not_validated', 'coverage' => 0.99, 'gen' => 1]);
		$state->method('activeProfile')->willReturn(['gen' => 1, 'model' => 'm', 'digest' => 'd', 'dim' => 3, 'query_prefix' => '', 'max_tokens' => 512]);
		$state->method('exactScanFiles')->willReturn(2000);
		$state->method('uncertainBelow')->willReturn(60.0);
		$state->method('embedTimeoutMs')->willReturn(1500);

		$embed = $this->createMock(EmbedClient::class);
		$embed->method('embedQuery')->willReturnCallback(fn () => $this->queryVector);

		$hits = $this->createMock(HitBuilder::class);
		$hits->method('build')->willReturnCallback(static function (string $q, array $alt, array $survivors): array {
			return array_map(static fn (array $s): array => ['file_id' => $s[1]->fileId, 'title' => $s[1]->node->getName(), 'chunk' => $s[0]['chunk_id'], 'etag' => $s[0]['etag']], $survivors);
		});

		$store = $this->createMock(SnapshotStore::class);
		$store->method('save')->willReturnCallback(function (string $uid, array $files, array $chunks, bool $capped): string {
			$qid = sprintf('00000000-0000-4000-8000-%012d', count($this->snapshots) + 1);
			$this->snapshots[$qid] = ['file_ids' => $files, 'chunk_ids' => $chunks, 'capped' => $capped, 'uid' => $uid];
			return $qid;
		});
		$store->method('load')->willReturnCallback(fn (string $qid, string $uid) => (($this->snapshots[$qid]['uid'] ?? null) === $uid) ? $this->snapshots[$qid] : null);

		$tree = array_fill_keys(array_keys($this->nodes), 9000) + [9000 => -1];
		$recheck = ScriptedRecheck::make($this->nodes, TreeChecker::make($tree, [[3, 'rag-excluded']], $this->tagged));
		return new Searcher($db, $principals, $cand, $recheck, $hits, new Glossary([['key' => 'rem', 'expansion' => 'resumen estadistico mensual', 'ambiguous' => false]]),
			$embed, $state, $store, new Cursor(self::SECRET), $log ?? $this->createMock(LoggerInterface::class));
	}

	private function req(array $over = []): SearchRequest {
		return SearchRequest::fromArray($over + ['query' => 'hemoglobina']);
	}

	/** @return list<int> */
	private function ids(array $out): array {
		return array_column($out['hits'], 'file_id');
	}

	public function testFirstPageThenTheNextAreContiguousWithNoDuplicates(): void {
		$this->corpus(60);
		$s = $this->searcher();
		$p1 = $s->search('b', $this->req());
		$this->assertSame('ok', $p1['status']);
		$this->assertSame(range(1, 25), $this->ids($p1));
		$this->assertSame(['from' => 1, 'to' => 25, 'total' => 60, 'capped' => false, 'size' => 25], $p1['page']);
		$this->assertNull($p1['cursors']['prev']);
		$p2 = $s->search('b', $this->req(['cursor' => $p1['cursors']['next']]));
		$this->assertSame(range(26, 50), $this->ids($p2));
		$p3 = $s->search('b', $this->req(['cursor' => $p2['cursors']['next']]));
		$this->assertSame(range(51, 60), $this->ids($p3));
		$this->assertNull($p3['cursors']['next']);
		$this->assertSame(['from' => 51, 'to' => 60, 'total' => 60, 'capped' => false, 'size' => 25], $p3['page']);
		$back = $s->search('b', $this->req(['cursor' => $p3['cursors']['prev']]));
		$this->assertSame(range(26, 50), $this->ids($back));
		$first = $s->search('b', $this->req(['cursor' => $p3['cursors']['first']]));
		$this->assertSame(range(1, 25), $this->ids($first));
		$last = $s->search('b', $this->req(['cursor' => $p1['cursors']['last']]));
		$this->assertSame(range(51, 60), $this->ids($last));
	}

	public function testFilesTheCallerCanNoLongerReadAreNeverCounted(): void {
		$this->corpus(3);
		foreach ([1, 2, 3] as $gone) {
			$this->nodes[$gone] = [];                                           // the stored principals still name the caller
		}
		$out = $this->searcher()->search('b', $this->req());
		$this->assertSame('ok', $out['status']);
		$this->assertSame([], $this->ids($out));
		$this->assertSame(['from' => 0, 'to' => 0, 'total' => 0, 'capped' => false, 'size' => 25], $out['page']);
		$this->assertNull($out['cursors']['next']);
		$this->assertNull($out['cursors']['last']);
	}

	public function testOneReadableMatchBesideRefusedFilesCountsOne(): void {
		$this->corpus(4);
		foreach ([1, 2, 3] as $gone) {
			$this->nodes[$gone] = [];
		}
		$out = $this->searcher()->search('b', $this->req());
		$this->assertSame([4], $this->ids($out));
		$this->assertSame([1, 1, 1], [$out['page']['from'], $out['page']['to'], $out['page']['total']]);
	}

	public function testAFileTaggedForExclusionIsNotCounted(): void {
		$this->corpus(4);
		$this->tagged = ['2' => ['3']];                                         // tagged; its erasure has not run yet
		$out = $this->searcher()->search('b', $this->req());
		$this->assertSame([1, 3, 4], $this->ids($out));
		$this->assertSame(3, $out['page']['total']);
	}

	public function testPreviousGoesBackToWhereThePreviousPageReallyStarted(): void {
		$this->corpus(80);
		$p1 = $this->searcher()->search('b', $this->req());
		foreach (range(30, 39) as $gone) {
			$this->nodes[$gone] = [];                                           // revoked after page 1: page 2 uses 35 positions to show 25 hits
		}
		$s = $this->searcher();                                                 // fresh recheck state, same snapshots
		$p2 = $s->search('b', $this->req(['cursor' => $p1['cursors']['next']]));
		$this->assertSame([...range(26, 29), ...range(40, 60)], $this->ids($p2));
		$p3 = $s->search('b', $this->req(['cursor' => $p2['cursors']['next']]));
		$this->assertSame(range(61, 80), $this->ids($p3));
		$back = $s->search('b', $this->req(['cursor' => $p3['cursors']['prev']]));
		$this->assertSame($this->ids($p2), $this->ids($back), 'not a page that starts inside page 2');
		$this->assertSame([], array_intersect($this->ids($back), $this->ids($p3)));
	}

	public function testHitsDroppedByTheRecheckAreRefilledFromLaterPositions(): void {
		$this->corpus(60);
		$p1 = $this->searcher()->search('b', $this->req());
		foreach ([27, 28, 30, 33] as $gone) {
			$this->nodes[$gone] = [];                                           // revoked after page 1 was shown
		}
		$s = $this->searcher();                                                 // fresh recheck state, same snapshots
		$p2 = $s->search('b', $this->req(['cursor' => $p1['cursors']['next']]));
		$this->assertSame([26, 29, 31, 32, ...range(34, 54)], $this->ids($p2), 'a full page despite four drops');
		$p3 = $s->search('b', $this->req(['cursor' => $p2['cursors']['next']]));
		$this->assertSame(range(55, 60), $this->ids($p3), 'the next page continues right after the last position used');
		$this->assertSame([], array_intersect($this->ids($p2), $this->ids($p3)));
	}

	public function testAPermissionRevokedBetweenPagesNeverSkipsAReadableFile(): void {
		$this->corpus(60);
		$s = $this->searcher();
		$p1 = $s->search('b', $this->req());
		$this->nodes[30] = [];                                                  // revoked after page 1 was shown
		$s = $this->searcher();                                                 // fresh recheck state, same snapshots
		$p2 = $s->search('b', $this->req(['cursor' => $p1['cursors']['next']]));
		$this->assertSame([...range(26, 29), ...range(31, 51)], $this->ids($p2));
	}

	public function testDateAndNameSortsReorderTheRankedListAndAreStableAcrossPages(): void {
		$this->corpus(40);
		$this->meta[3]['mtime'] = 9999;
		$this->meta[7]['mtime'] = 9999;                                         // a tie: file id breaks it
		$this->meta[1]['name'] = 'zeta.pdf';
		$s = $this->searcher();
		$new = $s->search('b', $this->req(['sort' => 'modified_desc']));
		$this->assertSame([3, 7, 40, 39], array_slice($this->ids($new), 0, 4));
		$old = $s->search('b', $this->req(['sort' => 'modified_asc']));
		$this->assertSame([1, 2, 4], array_slice($this->ids($old), 0, 3));
		$az = $s->search('b', $this->req(['sort' => 'name_asc', 'page_size' => 50]));
		$this->assertSame([10, 11, 12], array_slice($this->ids($az), 0, 3));
		$this->assertSame(1, $this->ids($az)[39], 'zeta last');
		$za = $s->search('b', $this->req(['sort' => 'name_desc']));
		$this->assertSame(1, $this->ids($za)[0]);
		$seen = [];
		$cursor = null;
		for ($i = 0; $i < 3; $i++) {
			$page = $s->search('b', $this->req(['sort' => 'modified_desc', 'cursor' => $cursor, 'page_size' => 25]));
			array_push($seen, ...$this->ids($page));
			$cursor = $page['cursors']['next'];
			if ($cursor === null) {
				break;
			}
		}
		$this->assertCount(40, array_unique($seen), 'every file exactly once across the pages');
	}

	public function testTheStoredListStopsAtTheReadableBoundAndSaysSo(): void {
		$this->rankedList(range(1, 1200));
		$s = $this->searcher();
		$out = $s->search('b', $this->req());
		$this->assertSame([Searcher::LISTED, true], [$out['page']['total'], $out['page']['capped']]);
		$last = $s->search('b', $this->req(['cursor' => $out['cursors']['last']]));
		$this->assertSame(range(76, 100), $this->ids($last), 'the last page of 25 opens position 75');

		$this->rankedList(range(1, 50 + Searcher::LISTED + 10), range(1, 50));
		$out = $this->searcher()->search('b', $this->req());
		$this->assertSame([Searcher::LISTED, true], [$out['page']['total'], $out['page']['capped']]);
		$this->assertSame(51, $this->ids($out)[0]);

		$this->rankedList(range(1, Searcher::LISTED - 1));
		$without = $this->searcher()->search('b', $this->req());
		$this->rankedList(range(1, Searcher::LISTED + 49), range(Searcher::LISTED, Searcher::LISTED + 49));
		$with = $this->searcher()->search('b', $this->req());
		$this->assertSame([Searcher::LISTED - 1, false], [$with['page']['total'], $with['page']['capped']]);
		$this->assertSame($without['page'], $with['page']);
		$this->assertSame(array_map('is_null', $without['cursors']), array_map('is_null', $with['cursors']));
	}

	public function testOneFileFailingInALaterRecheckBatchDoesNotFailTheSearch(): void {
		$this->rankedList(range(1, 130), [1]);
		$this->nodes[101] = new ExclusionUnavailable('the file layer failed for this file');
		$s = $this->searcher();
		$out = $s->search('b', $this->req());
		$this->assertSame('ok', $out['status']);
		$this->assertSame([Searcher::LISTED, true], [$out['page']['total'], $out['page']['capped']]);
		$last = $s->search('b', $this->req(['cursor' => $out['cursors']['last']]));
		$this->assertSame([...range(77, 100), 102], $this->ids($last), 'file 101 is not stored');
	}

	public function testAListThatReachedTheCapStaysCappedWhenTheRecheckRemovesFiles(): void {
		$this->rankedList(range(1, 1200), range(51, 1000));                      // B can read only 50 of the first 1 000
		$out = $this->searcher()->search('b', $this->req());
		$this->assertSame([50, true], [$out['page']['total'], $out['page']['capped']], 'files past the cap were never ranked: the list is incomplete');
	}

	public function testCursorPagesKeepTheCappedFlagOfPageOne(): void {
		$this->corpus(60);
		foreach ([true, false] as $full) {
			$this->windowFull = $full;
			$s = $this->searcher();
			$p1 = $s->search('b', $this->req(['page_size' => 25]));
			$next = $s->search('b', $this->req(['cursor' => $p1['cursors']['next']]));
			$last = $s->search('b', $this->req(['cursor' => $p1['cursors']['last']]));
			$prev = $s->search('b', $this->req(['cursor' => $last['cursors']['prev']]));
			$first = $s->search('b', $this->req(['cursor' => $last['cursors']['first']]));
			foreach (['page 1' => $p1, 'next' => $next, 'last' => $last, 'prev' => $prev, 'first' => $first] as $name => $page) {
				$this->assertSame(['ok', 60, $full], [$page['status'], $page['page']['total'], $page['page']['capped']], "$name, window full: " . json_encode($full));
			}
		}
	}

	public function testAFullKeywordWindowCapsOnlyTheKeywordMode(): void {
		$this->corpus(3);
		$this->vectorRows = [['file_id' => 1, 'chunk_id' => 10]];
		$this->windowFull = true;
		$capped = fn (string $mode): bool => $this->searcher()->search('b', $this->req(['mode' => $mode]))['page']['capped'];
		$this->assertTrue($capped('exacta'));
		$this->assertFalse($capped('mixta'), 'the window flag says nothing about a fused list');
		$this->assertFalse($capped('significado'));
		$this->semantic = false;
		$this->assertTrue($capped('mixta'), 'mixta that fell back to keyword search is keyword search');
	}

	public function testMixtaFusesBothLegsAndRunsThemUnderTheSameFilters(): void {
		$this->corpus(6);
		$this->nodes[7] = [$this->node('Informes')];                              // folder 7 is readable by this person (see the folder filter)
		$this->keywordRows = [['file_id' => 1, 'chunk_id' => 10], ['file_id' => 2, 'chunk_id' => 20], ['file_id' => 3, 'chunk_id' => 30]];
		$this->vectorRows = [['file_id' => 3, 'chunk_id' => 31], ['file_id' => 4, 'chunk_id' => 40], ['file_id' => 1, 'chunk_id' => 11]];
		$s = $this->searcher();
		$out = $s->search('b', $this->req(['mode' => 'mixta', 'filters' => ['types' => ['pdf'], 'folder_id' => 7]]));
		$this->assertSame([1, 3, 2, 4], $this->ids($out), 'files in both legs first, ties by id');
		$this->assertNull($out['notice']);
		$kinds = array_column($this->seen, 0);
		$this->assertSame(['exacta', 'significado'], $kinds);
		foreach ($this->seen as [, $args]) {
			$f = $args[0] === 1 ? $args[1] : $args[2];
			$this->assertSame(7, $f->folderId);
			$this->assertSame(['application/pdf'], $f->mimes);
		}
	}

	public function testOnlyTheKeywordModeRelaxesToOr(): void {
		$this->corpus(3);
		$this->vectorRows = [['file_id' => 1, 'chunk_id' => 10]];
		$this->searcher()->search('b', $this->req(['mode' => 'exacta']));
		$this->assertNull($this->seen[0][1][4], 'exacta: relax when the strict pass is thin');
		$this->seen = [];
		$this->searcher()->search('b', $this->req(['mode' => 'mixta']));
		$this->assertFalse($this->seen[0][1][4], 'mixta: strict keyword leg, the vector leg covers the fuzzy part (S4 evaluation)');
	}

	public function testSignificadoUsesOnlyTheVectorLeg(): void {
		$this->corpus(3);
		$this->vectorRows = [['file_id' => 3, 'chunk_id' => 30], ['file_id' => 1, 'chunk_id' => 10]];
		$out = $this->searcher()->search('b', $this->req(['mode' => 'significado']));
		$this->assertSame([3, 1], $this->ids($out));
		$this->assertSame(['significado'], array_column($this->seen, 0));
	}

	public function testGlossaryAlternativesReachTheKeywordLegButNotTheVectorOne(): void {
		$this->corpus(2);
		$this->searcher()->search('b', $this->req(['query' => 'REM junio']));
		$this->assertSame([['rem', 'resumen estadistico mensual']], $this->seen[0][1][1]);
	}

	public function testSemanticModesFallBackToKeywordWithANoticeWhenNotAvailable(): void {
		$this->corpus(3);
		$this->semantic = false;
		$out = $this->searcher()->search('b', $this->req(['mode' => 'mixta']));
		$this->assertSame([1, 2, 3], $this->ids($out));
		$this->assertSame('semantic_unavailable', $out['notice']['code']);
		$this->assertStringContainsString('palabras', $out['notice']['message']);
		$this->assertSame(['exacta'], array_column($this->seen, 0));
	}

	public function testAFailedQueryEmbeddingFallsBackToKeywordWithADifferentNotice(): void {
		$this->corpus(3);
		$this->queryVector = null;
		$out = $this->searcher()->search('b', $this->req(['mode' => 'significado']));
		$this->assertSame([1, 2, 3], $this->ids($out), 'never an empty list because the model is down');
		$this->assertSame('semantic_failed', $out['notice']['code']);
	}

	public function testExactScanIsUsedBelowTheThresholdOrWithoutAnIndex(): void {
		$this->corpus(3);
		$this->vectorRows = [['file_id' => 1, 'chunk_id' => 10]];
		foreach ([[10, true, true], [5000, true, false], [5000, false, true]] as [$accessible, $ann, $expectExact]) {
			$this->seen = [];
			$this->accessible = $accessible;
			$this->ann = $ann;
			$this->searcher()->search('b', $this->req(['mode' => 'significado']));
			$vec = array_values(array_filter($this->seen, static fn ($s) => $s[0] === 'significado'))[0];
			$this->assertSame($expectExact, $vec[1][3], "accessible=$accessible ann=" . json_encode($ann));
		}
	}

	public function testCursorsAreBoundToTheUserAndRejectedWhenTamperedOrExpired(): void {
		$this->corpus(60);
		$s = $this->searcher();
		$p1 = $s->search('b', $this->req());
		$other = $s->search('mallory', $this->req(['cursor' => $p1['cursors']['next']]));
		$this->assertSame(['invalid_cursor', []], [$other['status'], $other['hits']]);
		$bad = $s->search('b', $this->req(['cursor' => $p1['cursors']['next'] . 'x']));
		$this->assertSame('invalid_cursor', $bad['status']);
		$this->snapshots = [];
		$gone = $s->search('b', $this->req(['cursor' => $p1['cursors']['next']]));
		$this->assertSame('cursor_expired', $gone['status']);
		$this->assertSame([], $gone['hits']);
	}

	public function testABlankQueryNeedsNoDatabase(): void {
		$out = $this->searcher()->search('b', $this->req(['query' => '  ']));
		$this->assertSame('ok', $out['status']);
		$this->assertSame([], $out['hits']);
		$this->assertSame([], $this->seen);
	}

	public function testFailuresReturnNoHitsAndTheLogHoldsNoQueryText(): void {
		$this->corpus(3);
		$messages = [];
		$log = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $l) {
			$log->method($l)->willReturnCallback(function (string $m) use (&$messages): void { $messages[] = $m; });
		}
		$boom = new Searcher(
			$this->createMock(IndexDb::class), $this->createMock(PrincipalResolver::class), $this->createMock(CandidateQuery::class),
			ScriptedRecheck::make([], TreeChecker::make([1 => 1], [], [])), $this->createMock(HitBuilder::class), new Glossary([]),
			$this->createMock(EmbedClient::class), $this->createMock(VectorState::class), $this->createMock(SnapshotStore::class), new Cursor(self::SECRET), $log,
		);
		$out = $boom->search('b', $this->req(['query' => 'DATO-CENTINELA-77']));
		$this->assertSame(['unavailable', []], [$out['status'], $out['hits']]);
		$this->assertStringNotContainsString('CENTINELA', implode("\n", $messages));
		$this->assertNotEmpty($messages);
	}

	public function testStatementTimeoutAndExclusionOutageHaveTheirOwnAnswers(): void {
		$principals = $this->createMock(PrincipalResolver::class);
		$principals->method('forUser')->willReturn(['u:b']);
		$mk = fn (IndexDb $d, $recheck) => new Searcher($d, $principals, $this->createMock(CandidateQuery::class), $recheck, $this->createMock(HitBuilder::class), new Glossary([]),
			$this->createMock(EmbedClient::class), $this->createMock(VectorState::class), $this->createMock(SnapshotStore::class), new Cursor(self::SECRET), $this->createMock(LoggerInterface::class));
		foreach ([
			// what Nextcloud wraps when the server's lc_messages is Spanish
			'An exception occurred while executing a query: SQLSTATE[57014]: Query canceled: 7 ERROR:  cancelando la sentencia debido a que se agotó el tiempo de espera de sentencias' => 'too_many',
			'SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout' => 'too_many',
			'SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout' => 'unavailable',
		] as $message => $status) {
			$db = $this->createMock(IndexDb::class);
			$db->method('withTimeout')->willThrowException(new \RuntimeException($message));
			$this->assertSame($status, $mk($db, ScriptedRecheck::make([], TreeChecker::make([1 => 1], [], [])))->search('b', $this->req())['status'], $message);
		}

		$this->corpus(3);
		$ok = $this->createMock(IndexDb::class);
		$ok->method('withTimeout')->willReturnCallback(static fn (int $ms, callable $w) => $w());
		$ok->method('rows')->willReturn([]);
		$cand = $this->createMock(CandidateQuery::class);
		$cand->method('exacta')->willReturn([[['file_id' => 1, 'chunk_id' => 10]], false]);
		$recheck = ScriptedRecheck::make([1 => new ExclusionUnavailable('down')], TreeChecker::make([1 => 1], [], []));
		$s = new Searcher($ok, $principals, $cand, $recheck, $this->createMock(HitBuilder::class), new Glossary([]),
			$this->createMock(EmbedClient::class), $this->createMock(VectorState::class), $this->createMock(SnapshotStore::class), new Cursor(self::SECRET), $this->createMock(LoggerInterface::class));
		$this->assertSame('unavailable', $s->search('b', $this->req())['status']);
	}

	public function testModesListsWhichAreEnabledAndWhy(): void {
		$this->semantic = false;
		$modes = $this->searcher()->modes();
		$this->assertSame([['mode' => 'exacta', 'enabled' => true, 'reason' => null], ['mode' => 'significado', 'enabled' => false, 'reason' => 'not_validated'], ['mode' => 'mixta', 'enabled' => false, 'reason' => 'not_validated']], $modes);
		$this->semantic = true;
		$this->assertTrue($this->searcher()->modes()[1]['enabled']);
	}
}
