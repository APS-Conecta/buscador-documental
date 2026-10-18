<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\VecBackfill;

/** Housekeeping for the vector lane: queue what lacks vectors, measure coverage, ready a generation when it is full enough. */
class VecBackfillTest extends DbTestCase {
	private function gen(int $n, string $state = 'building'): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation($n, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', '', '', 8192)");
		if ($state !== 'building') {
			$a->exec("SELECT aps_search.mark_generation_ready($n)");
		}
		if ($state === 'active') {
			$a->exec("SELECT aps_search.activate_generation($n)");
		}
	}

	private function indexed(int $id): int {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, 'e1', 'indexed')");
		return (int)$a->query("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES ($id, 1, 0, 't') RETURNING chunk_id")->fetchColumn();
	}

	private function embed(int $gen, int $chunk, int $file): void {
		$this->app()->exec("INSERT INTO aps_search.chunk_vec_$gen (chunk_id, file_id, vec) VALUES ($chunk, $file, '[1,0,0]')");
	}

	private function backfill(): VecBackfill {
		$db = new IndexDb($this->connection($this->app()));
		return new VecBackfill($db, new Settings($db));
	}

	private function setting(string $k): ?string {
		$v = $this->app()->query("SELECT value FROM aps_search.settings WHERE key = '$k'")->fetchColumn();
		return $v === false ? null : (string)$v;
	}

	public function testItQueuesFilesWithoutVectorsInBatchesAndMeasuresCoverage(): void {
		for ($i = 1; $i <= 10; $i++) {
			$this->indexed($i);
		}
		$this->gen(1);
		$r = $this->backfill()->tick(4);
		$this->assertSame([4, 0.0], [$r['queued'], $r['coverage'][1]]);
		$this->assertSame(4, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec'")->fetchColumn());
		$this->assertSame('0.0000', $this->setting('vec_coverage_1'));
		$this->assertSame(4, $this->backfill()->tick(4)['queued'], 'the next batch of four');
	}

	public function testAGenerationBecomesReadyOnceCoverageReachesTheThresholdAndGetsItsIndex(): void {
		$chunks = [];
		for ($i = 1; $i <= 20; $i++) {
			$chunks[$i] = $this->indexed($i);
		}
		$this->gen(1);
		for ($i = 1; $i <= 18; $i++) {
			$this->embed(1, $chunks[$i], $i);
		}
		$r = $this->backfill()->tick(100);
		$this->assertEqualsWithDelta(0.9, $r['coverage'][1], 1e-9);
		$this->assertSame('building', $this->app()->query('SELECT state FROM aps_search.embedding_profile WHERE gen = 1')->fetchColumn(), '0.90 < 0.95');
		$this->embed(1, $chunks[19], 19);
		$r = $this->backfill()->tick(100);
		$this->assertEqualsWithDelta(0.95, $r['coverage'][1], 1e-9);
		$this->assertSame('ready', $this->app()->query('SELECT state FROM aps_search.embedding_profile WHERE gen = 1')->fetchColumn());
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM pg_indexes WHERE tablename = 'chunk_vec_1' AND indexdef ILIKE '%hnsw%'")->fetchColumn());
		$this->assertSame([1], $r['ready'], 'the report names the generation it readied');
	}

	public function testTheActiveGenerationFeedsTheCoverageSearchReads(): void {
		$c1 = $this->indexed(1);
		$this->indexed(2);
		$this->gen(1, 'active');
		$this->embed(1, $c1, 1);
		$this->backfill()->tick(100);
		$this->assertSame('0.5000', $this->setting('vec_coverage'));
		$this->assertSame('active', $this->app()->query('SELECT state FROM aps_search.embedding_profile WHERE gen = 1')->fetchColumn(), 'an active generation never changes state here');
	}

	public function testPauseStopsQueueingButNotTheMeasuring(): void {
		$this->indexed(1);
		$this->gen(1, 'active');
		(new Settings(new IndexDb($this->connection($this->app()))))->set('paused', '1');
		$r = $this->backfill()->tick(100);
		$this->assertSame(0, $r['queued']);
		$this->assertSame('0.0000', $this->setting('vec_coverage'));
	}

	public function testRetiredGenerationsAreLeftAlone(): void {
		$this->indexed(1);
		$this->gen(1, 'active');
		$this->gen(2, 'ready');
		$this->app()->exec('SELECT aps_search.activate_generation(2)');       // gen 1 is now retired
		$r = $this->backfill()->tick(100);
		$this->assertArrayNotHasKey(1, $r['coverage']);
		$this->assertArrayHasKey(2, $r['coverage']);
	}

	public function testWithoutAnyGenerationItIsANoOp(): void {
		$this->indexed(1);
		$r = $this->backfill()->tick(100);
		$this->assertSame(['queued' => 0, 'coverage' => [], 'ready' => []], $r);
	}

	public function testAnEmptyCorpusCountsAsFullyCovered(): void {
		$this->gen(1, 'active');
		$r = $this->backfill()->tick(100);
		$this->assertSame(1.0, $r['coverage'][1]);
	}
}
