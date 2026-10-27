<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use PDO;

/** S4 phase 2: the vector lane through the worker's functions only, and the lane-aware queue. */
class S4VecFunctionsTest extends DbTestCase {
	private function generation(int $gen, string $state = 'active', int $dim = 3): void {
		$app = $this->app();
		$app->prepare('SELECT aps_search.create_generation(?, ?, ?, ?, ?, ?, ?, ?, ?)')
			->execute([$gen, 'bge-m3', 'bge-m3:567m', 'sha256:abc', $dim, 'f16', 'query: ', 'passage: ', 8192]);
		if ($state !== 'building') {
			$app->exec("SELECT aps_search.mark_generation_ready($gen)");
		}
		if ($state === 'active') {
			$app->exec("SELECT aps_search.activate_generation($gen)");
		}
	}

	/** Indexes file $id with $n chunks through the real text lane and returns nothing. */
	private function indexed(int $id, int $n = 2, string $etag = 'e1'): void {
		$this->seedFile($id, $etag);
		$w = $this->worker();
		$claim = $this->claim($w, 'text-w');
		$chunks = [];
		for ($i = 0; $i < $n; $i++) {
			$chunks[] = ['page_no' => 1, 'ord' => $i, 'heading' => $i === 0 ? 'Titulo' : null, 'text' => "texto $id $i"];
		}
		$this->write($w, $claim, ['chunks' => json_encode($chunks)]);
	}

	/** @return array{job_id:int, lease_epoch:int, file_id:int, etag:string}|null */
	private function claimVec(PDO $w, string $who = 'vec-w', int $lease = 900): ?array {
		$s = $w->prepare('SELECT * FROM aps_search.claim_job(?, 1, ?, ?)');
		$s->execute(['vec', $who, $lease]);
		$r = $s->fetch(PDO::FETCH_ASSOC);
		return $r === false ? null : array_map(static fn ($v) => is_numeric($v) ? (int)$v : $v, $r);
	}

	private function writeVec(PDO $w, array $c, array $ids, array $vecs, ?string $etag = null): bool {
		$s = $w->prepare('SELECT aps_search.write_vectors(?, ?, ?, ?::bigint[], ?::text[])');
		$s->execute([$c['job_id'], $c['lease_epoch'], $etag ?? $c['etag'], '{' . implode(',', $ids) . '}', '{' . implode(',', array_map(static fn ($v) => '"' . $v . '"', $vecs)) . '}']);
		return (bool)$s->fetchColumn();
	}

	/** @return list<int> */
	private function chunkIds(int $file): array {
		return array_map('intval', $this->app()->query("SELECT chunk_id FROM aps_search.chunk WHERE file_id = $file ORDER BY ord")->fetchAll(PDO::FETCH_COLUMN));
	}

	public function testIndexingQueuesOneVecJobPerLiveGenerationAndNoneWithoutAny(): void {
		$this->indexed(10);
		$this->assertSame(0, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec'")->fetchColumn(), 'no generation, no vec job');
		$this->generation(1, 'active');
		$this->generation(2, 'building');
		$this->generation(3, 'ready');
		$this->app()->exec("UPDATE aps_search.embedding_profile SET state = 'retired' WHERE gen = 3");
		$this->indexed(11);
		$rows = $this->app()->query("SELECT gen || ':' || idem_key FROM aps_search.job WHERE lane = 'vec' ORDER BY gen")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame(['1:11:e1:1', '2:11:e1:2'], $rows, 'active and building, not retired');
		$this->assertSame('pending', $this->app()->query('SELECT vec_state FROM aps_search.file_state WHERE file_id = 11')->fetchColumn());
	}

	public function testTheWorkerRunsTheWholeLaneThroughFunctionsOnly(): void {
		$this->generation(1);
		$this->indexed(10, 2);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$this->assertSame(10, $c['file_id']);
		$p = $w->prepare('SELECT * FROM aps_search.vec_profile(?, ?)');
		$p->execute([$c['job_id'], $c['lease_epoch']]);
		$this->assertSame(['gen' => 1, 'model' => 'bge-m3:567m', 'digest' => 'sha256:abc', 'dim' => 3, 'query_prefix' => 'query: ', 'passage_prefix' => 'passage: ', 'max_tokens' => 8192],
			array_map(static fn ($v) => is_numeric($v) ? (int)$v : $v, $p->fetch(PDO::FETCH_ASSOC)));
		$s = $w->prepare('SELECT * FROM aps_search.vec_chunks(?, ?, ?)');
		$s->execute([$c['job_id'], $c['lease_epoch'], $c['etag']]);
		$chunks = $s->fetchAll(PDO::FETCH_ASSOC);
		$this->assertCount(2, $chunks);
		$this->assertSame('Titulo', $chunks[0]['heading']);
		$ids = array_map(static fn ($r) => (int)$r['chunk_id'], $chunks);

		$this->assertFalse($this->writeVec($w, $c, [$ids[0]], ['[1,0,0]']), 'one of two chunks: not complete, job stays');
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec'")->fetchColumn());
		$s->execute([$c['job_id'], $c['lease_epoch'], $c['etag']]);
		$this->assertCount(1, $s->fetchAll(), 'a retry only gets the chunks that still lack a vector');
		$this->assertTrue($this->writeVec($w, $c, [$ids[1]], ['[0,1,0]']));
		$this->assertSame(0, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec'")->fetchColumn());
		$this->assertSame('embedded', $this->app()->query('SELECT vec_state FROM aps_search.file_state WHERE file_id = 10')->fetchColumn());
		$this->assertSame(2, (int)$this->app()->query('SELECT count(*) FROM aps_search.chunk_vec_1')->fetchColumn());
	}

	public function testWriteVectorsIsIdempotentForTheSameChunk(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$id = $this->chunkIds(10)[0];
		$this->assertTrue($this->writeVec($w, $c, [$id], ['[1,0,0]']));
		$this->assertSame(1, (int)$this->app()->query('SELECT count(*) FROM aps_search.chunk_vec_1')->fetchColumn());
	}

	public function testBadVectorsAndForeignChunksAreRefusedAndNothingIsWritten(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$this->indexed(11, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$mine = $this->chunkIds(10)[0];
		$theirs = $this->chunkIds(11)[0];
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$mine], ['[1,0]'])), 'wrong dimension');
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$mine], ['[1,0,0,0]'])));
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$mine], ['[NaN,0,0]'])));
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$mine], ['[Infinity,0,0]'])));
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$mine], ['not a vector'])));
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$theirs], ['[1,0,0]'])), 'a chunk of another file');
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$mine, $mine + 999], ['[1,0,0]'])), 'ids and vectors must pair up');
		$this->assertSame(0, (int)$this->app()->query('SELECT count(*) FROM aps_search.chunk_vec_1')->fetchColumn());
	}

	public function testStaleLeaseAndChangedEtagAreRefused(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$id = $this->chunkIds(10)[0];
		$this->assertStringContainsString('etag_mismatch', $this->failure(fn () => $this->writeVec($w, $c, [$id], ['[1,0,0]'], 'other')));
		$stale = $c;
		$stale['lease_epoch'] = $c['lease_epoch'] + 1;
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $this->writeVec($w, $stale, [$id], ['[1,0,0]'])));
		$p = $w->prepare('SELECT * FROM aps_search.vec_profile(?, ?)');
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $p->execute([$c['job_id'], $c['lease_epoch'] + 1])));
		$this->app()->exec('UPDATE aps_search.job SET lease_until = now() - interval \'1 second\'');
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $this->writeVec($w, $c, [$id], ['[1,0,0]'])));
	}

	public function testAJobForARetiredGenerationCannotWrite(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$this->generation(2);
		$this->app()->exec('SELECT aps_search.activate_generation(2)');
		$this->assertStringContainsString('retired', $this->failure(fn () => $this->writeVec($w, $c, $this->chunkIds(10), ['[1,0,0]'])));
	}

	public function testAReindexCascadesVectorsAndQueuesTheLaneAgain(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$this->writeVec($w, $c, $this->chunkIds(10), ['[1,0,0]']);
		$this->app()->exec("UPDATE aps_search.file_state SET etag = 'e2', text_state = 'pending' WHERE file_id = 10");
		$this->app()->exec("INSERT INTO aps_search.job (lane, file_id, idem_key) VALUES ('text', 10, '10:e2')");
		$t = $this->claim($w, 'text-w');
		$this->write($w, $t);
		$this->assertSame(0, (int)$this->app()->query('SELECT count(*) FROM aps_search.chunk_vec_1')->fetchColumn(), 'old vectors went with the old chunks');
		$this->assertSame('pending', $this->app()->query('SELECT vec_state FROM aps_search.file_state WHERE file_id = 10')->fetchColumn());
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec' AND idem_key = '10:e2:1'")->fetchColumn());
	}

	public function testTheLanesHaveSeparateCapsSoEmbeddingCannotStarveOcr(): void {
		$this->generation(1);
		foreach ([10, 11, 12] as $id) {
			$this->indexed($id, 1);
		}
		$w = $this->worker();
		$a = $this->claimVec($w, 'vec-w');
		$b = $this->claimVec($w, 'vec-w');
		$this->assertNotNull($a);
		$this->assertNotNull($b);
		$this->assertNull($this->claimVec($w, 'vec-w'), 'two vec claims per worker');
		$this->seedFile(20);
		$this->assertNotNull($this->claim($w, 'vec-w'), 'the text lane is not blocked by vec claims');
	}

	public function testGivingUpAVecJobMarksVecStateAndLeavesTheTextAlone(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$this->claimVec($w);
		$this->app()->exec("UPDATE aps_search.job SET attempts = 5, lease_until = now() - interval '1 second' WHERE lane = 'vec'");
		$this->claimVec($w, 'other');                       // claiming runs the give-up step
		$row = $this->app()->query('SELECT text_state, vec_state FROM aps_search.file_state WHERE file_id = 10')->fetch(PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'indexed', 'vec_state' => 'failed'], $row);
		$this->assertSame(0, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec'")->fetchColumn());
		$this->assertSame(1, $this->rowCount('chunk', 10), 'the text stays searchable');
	}

	public function testFailJobOnTheVecLane(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$fail = $w->prepare('SELECT aps_search.fail_job(?, ?, ?, ?::boolean)');
		$fail->execute([$c['job_id'], $c['lease_epoch'], 'embed_failed', 'true']);
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec' AND lease_until IS NULL AND not_before > now()")->fetchColumn(), 'retryable: waits out a backoff');
		$this->app()->exec("UPDATE aps_search.job SET not_before = now()");
		$c = $this->claimVec($w);
		$fail->execute([$c['job_id'], $c['lease_epoch'], 'model_mismatch', 'false']);
		$row = $this->app()->query('SELECT text_state, vec_state FROM aps_search.file_state WHERE file_id = 10')->fetch(PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'indexed', 'vec_state' => 'failed'], $row);
		$this->assertStringContainsString('invalid reason', $this->failure(function () use ($w, $c) {
			$w->prepare('SELECT aps_search.fail_job(?, ?, ?, ?::boolean)')->execute([$c['job_id'], $c['lease_epoch'], 'because', 'false']);
		}));
	}

	public function testBackfillQueuesIndexedFilesWithoutVectorsOnceAndHonoursTheLimit(): void {
		foreach ([10, 11, 12] as $id) {
			$this->indexed($id, 1);
		}
		$this->generation(1, 'building');
		$app = $this->app();
		$this->assertSame(2, (int)$app->query('SELECT aps_search.enqueue_vec_backfill(1, 2)')->fetchColumn());
		$this->assertSame(1, (int)$app->query('SELECT aps_search.enqueue_vec_backfill(1, 10)')->fetchColumn(), 'only the file that has no job yet');
		$this->assertSame(0, (int)$app->query('SELECT aps_search.enqueue_vec_backfill(1, 10)')->fetchColumn());
		$this->assertSame(3, (int)$app->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec' AND gen = 1")->fetchColumn());
		$app->exec("SELECT aps_search.erase_files(ARRAY[12]::bigint[], 'excluded')");
		$app->exec("DELETE FROM aps_search.job WHERE lane = 'vec'");
		$this->assertSame(2, (int)$app->query('SELECT aps_search.enqueue_vec_backfill(1, 10)')->fetchColumn(), 'an erased file is never queued');
	}

	public function testCoverageCountsFilesWithEveryChunkEmbedded(): void {
		$this->generation(1);
		$this->indexed(10, 2);
		$this->indexed(11, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$this->writeVec($w, $c, $this->chunkIds($c['file_id']), array_fill(0, count($this->chunkIds($c['file_id'])), '[1,0,0]'));
		$row = $this->app()->query('SELECT files, embedded FROM aps_search.vec_coverage(1)')->fetch(PDO::FETCH_NUM);
		$this->assertSame([2, 1], array_map('intval', $row));
	}

	public function testThePausedFlagStopsTheVecLaneToo(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$this->app()->exec("INSERT INTO aps_search.settings (key, value) VALUES ('paused', '1')");
		$this->assertNull($this->claimVec($this->worker()));
	}

	public function testDuplicateChunkIdsAreABadVectorNotARawError(): void {
		$this->generation(1);
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$id = $this->chunkIds(10)[0];
		$this->assertStringContainsString('bad_vector', $this->failure(fn () => $this->writeVec($w, $c, [$id, $id], ['[1,0,0]', '[0,1,0]'])));
	}

	public function testBackfillLeavesPermanentlyFailedFilesAlone(): void {
		$this->indexed(10, 1);
		$this->indexed(11, 1);
		$this->generation(1, 'building');
		$this->assertSame(1, (int)$this->app()->query('SELECT aps_search.enqueue_vec_backfill(1, 1)')->fetchColumn());
		$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));     // file 10's job: the worker gives up for good
		$this->assertSame(1, (int)$this->app()->query('SELECT aps_search.enqueue_vec_backfill(1, 10)')->fetchColumn(), 'only the file that has not failed');
		$this->assertSame(0, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'vec' AND file_id = 10")->fetchColumn());
	}

	public function testActivationAndDropAreSerialised(): void {
		$this->generation(1);
		$this->generation(2, 'ready');
		$app = $this->app();
		$app->beginTransaction();
		$app->exec('SELECT aps_search.activate_generation(2)');
		$other = $this->app();
		$other->exec("SET lock_timeout = '300ms'");
		$this->assertStringContainsString('lock timeout', $this->failure(static fn () => $other->exec('SELECT aps_search.drop_generation(2)')), 'a drop waits for a concurrent activation instead of racing it');
		$app->rollBack();
	}

	public function testFinishingABuildingGenerationStampsTheCompletionTimeWithoutTouchingTheActiveState(): void {
		$this->generation(1, 'active');
		$this->indexed(10, 1);
		$w = $this->worker();
		$c = $this->claimVec($w);
		$this->writeVec($w, $c, $this->chunkIds(10), ['[1,0,0]']);
		$this->app()->exec("UPDATE aps_search.file_state SET embedded_at = now() - interval '5 hours' WHERE file_id = 10");
		$this->generation(2, 'building');
		$this->app()->exec('SELECT aps_search.enqueue_vec_backfill(2, 10)');
		$c2 = $this->claimVec($w, 'vec-w2');
		$this->assertNotNull($c2);
		$this->writeVec($w, $c2, $this->chunkIds(10), ['[0,1,0]']);
		$row = $this->app()->query("SELECT vec_state, embedded_at > now() - interval '1 minute' AS fresh FROM aps_search.file_state WHERE file_id = 10")->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('embedded', $row['vec_state']);
		$this->assertTrue((bool)$row['fresh'], 'the completion of the other generation counts for the rate');
	}
}
