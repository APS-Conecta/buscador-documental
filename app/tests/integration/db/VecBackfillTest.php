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

	public function testAReadyOrActiveGenerationThatLostItsIndexGetsItBackOnTheNextTick(): void {
		$this->indexed(1);
		$this->gen(1, 'ready');
		$this->backfill()->tick(10);
		$idx = static fn ($t) => (int)$t->app()->query("SELECT count(*) FROM pg_indexes WHERE tablename = 'chunk_vec_1' AND indexdef ILIKE '%hnsw%'")->fetchColumn();
		$this->assertSame(1, $idx($this), 'built for a ready generation even though it was not readied by this tick');
		self::connect('postgres', 'dev', self::$dbName)->exec('DROP INDEX aps_search.chunk_vec_1_hnsw');
		$this->backfill()->tick(10);
		$this->assertSame(1, $idx($this));
	}

	/** @return list<string> idem keys of the file's vec jobs, in generation order */
	private function vecJobs(int $file): array {
		return $this->app()->query("SELECT idem_key FROM aps_search.job WHERE lane = 'vec' AND file_id = $file ORDER BY gen")->fetchAll(\PDO::FETCH_COLUMN);
	}

	public function testABuildingGenerationStopsRequeuingAFileItGaveUpOn(): void {
		$this->gen(1, 'active');
		$this->embed(1, $this->indexed(7), 7);
		$this->gen(2);
		$this->backfill()->tick(100);
		$c = $this->claim($this->worker(), 'vec-w', lane: 'vec');
		$this->assertSame(['7:e1:2'], [$this->vecJobs(7)[0]], 'only generation 2 lacks the vectors');
		$this->giveUpVecJob($c);
		$this->backfill()->tick(100);
		$this->assertSame([], $this->vecJobs(7), 'the generation that gave up does not queue the file again');
	}

	public function testANewGenerationQueuesAFileThatFailedOnlyInTheActiveOne(): void {
		$this->indexed(7);
		$this->gen(1, 'active');
		$this->backfill()->tick(100);
		$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));
		$this->gen(2);
		$this->backfill()->tick(100);
		$this->assertSame(['7:e1:2'], $this->vecJobs(7), 'generation 2 gets its own try; generation 1 does not try again');
	}

	public function testAFailureInTheActiveGenerationStillStopsItsRequeue(): void {
		$this->indexed(7);
		$this->gen(1, 'active');
		$this->backfill()->tick(100);
		$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));
		$this->backfill()->tick(100);
		$this->assertSame([], $this->vecJobs(7));
		$this->assertSame('failed', $this->app()->query('SELECT vec_state FROM aps_search.file_state WHERE file_id = 7')->fetchColumn());
	}

	public function testTheAttemptsCapRecordsAFailureForTheJobsGeneration(): void {
		$this->gen(1, 'active');
		$this->embed(1, $this->indexed(7), 7);
		$this->gen(2);
		$this->backfill()->tick(100);
		$this->claim($this->worker(), 'vec-w', lane: 'vec');
		$this->app()->exec("UPDATE aps_search.job SET attempts = 5, lease_until = now() - interval '1 second' WHERE lane = 'vec'");
		$this->worker()->query("SELECT * FROM aps_search.claim_job('vec', 1, 'other', 900)")->fetchAll();     // runs the give-up step
		$this->assertSame([], $this->vecJobs(7), 'given up after five claims');
		$this->backfill()->tick(100);
		$this->assertSame([], $this->vecJobs(7), 'and generation 2 does not queue it again');
	}

	public function testNewTextClearsFailureRecordsAndQueuesEveryLiveGeneration(): void {
		$this->gen(1, 'active');
		$this->embed(1, $this->indexed(7), 7);
		$this->gen(2);
		$this->backfill()->tick(100);
		$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));     // generation 2 gave up on file 7
		$this->app()->exec("INSERT INTO aps_search.job (lane, file_id, idem_key) VALUES ('text', 7, '7:e1:again')");
		$w = $this->worker();
		$this->write($w, $this->claim($w));                                 // the file is read again
		$this->app()->exec("DELETE FROM aps_search.job WHERE lane = 'vec'");     // leave the queueing to the backfill
		$this->backfill()->tick(100);
		$this->assertSame(['7:e1:1', '7:e1:2'], $this->vecJobs(7));
	}

	public function testAGiveUpForAnOlderVersionRecordsNothing(): void {
		$this->indexed(7);
		$this->gen(1, 'active');
		$this->backfill()->tick(100);
		$c = $this->claim($this->worker(), 'vec-w', lane: 'vec');
		$this->app()->exec("UPDATE aps_search.file_state SET etag = 'e2' WHERE file_id = 7");       // the file changed meanwhile
		$this->giveUpVecJob($c);
		$this->assertSame('pending', $this->app()->query('SELECT vec_state FROM aps_search.file_state WHERE file_id = 7')->fetchColumn());
		$this->backfill()->tick(100);
		$this->assertSame(['7:e2:1'], $this->vecJobs(7), 'the new version gets its own job');
	}

	public function testRetryFailedPutsFailedFilesBackInTheQueue(): void {
		$this->indexed(1);
		$this->indexed(2);
		$this->gen(1, 'active');
		$this->app()->exec("UPDATE aps_search.file_state SET vec_state = 'failed' WHERE file_id = 1");
		$this->assertSame(1, $this->backfill()->retryFailed());
		$this->assertSame('pending', $this->app()->query('SELECT vec_state FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
		$this->assertSame(0, $this->backfill()->retryFailed());
	}
}
