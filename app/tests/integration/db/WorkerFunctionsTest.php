<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\IndexEraser;

/** A-SEC-4 part 2 and A-SEC-3 (H1/H2): claim, write, zombie workers, erase races. */
class WorkerFunctionsTest extends DbTestCase {
	public function testHappyPathIndexesAndRemovesJob(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		$this->assertSame(10, $c['file_id']);
		$this->assertSame(1, $c['lease_epoch']);
		$this->write($w, $c);
		$this->assertSame(1, $this->rowCount('page', 10));
		$this->assertSame(1, $this->rowCount('chunk', 10));
		$this->assertSame(0, $this->rowCount('job'));
		$state = self::connect('nextcloud', 'dev', self::$dbName)->query('SELECT text_state, content_hash, ocr_ver FROM aps_search.file_state WHERE file_id = 10')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'indexed', 'content_hash' => 'h1', 'ocr_ver' => 'ocrmypdf-test'], $state);
	}

	public function testChunkTsvSearchesSpanishWithoutAccents(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$this->write($w, $this->claim($w), ['chunks' => json_encode([['page_no' => 1, 'ord' => 0, 'text' => 'La señora recibió la inyección']])]);
		$n = self::connect('nextcloud', 'dev', self::$dbName)->query("SELECT count(*) FROM aps_search.chunk WHERE tsv @@ websearch_to_tsquery('aps_search.spanish_unaccent', 'inyeccion recibio')")->fetchColumn();
		$this->assertSame(1, (int)$n);
	}

	public function testPayloadFileIdIsIgnoredAndRowsLandOnTheClaimedFile(): void {
		$this->seedFile(10);
		$this->seedFile(11);
		$w = $this->worker();
		$c = $this->claim($w);
		$this->assertSame(10, $c['file_id']);
		$this->write($w, $c, [
			'pages' => json_encode([['file_id' => 11, 'page_no' => 1, 'text' => 'x', 'ocr_conf' => 1, 'source' => 'ocr']]),
			'chunks' => json_encode([['file_id' => 11, 'page_no' => 1, 'ord' => 0, 'text' => 'x']]),
		]);
		$this->assertSame(1, $this->rowCount('chunk', 10));
		$this->assertSame(0, $this->rowCount('chunk', 11));
		$this->assertSame(0, $this->rowCount('page', 11));
	}

	public function testZombieWorkerWithOldEpochCannotOverwrite(): void {
		$this->seedFile(10);
		$w1 = $this->worker();
		$w2 = $this->worker();
		$first = $this->claim($w1, 'w1', 1);
		sleep(2);                                   // the 1 s lease expires
		$second = $this->claim($w2, 'w2');
		$this->assertSame(2, $second['lease_epoch']);
		$msg = $this->failure(fn () => $this->write($w1, $first));
		$this->assertStringContainsString('stale_lease', $msg);
		$this->assertSame(0, $this->rowCount('page'));
		$this->assertSame(0, $this->rowCount('chunk'));
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $this->write($w1, $second + ['x' => 1], ['epoch' => 1])));
		$this->write($w2, $second);                 // the live holder still succeeds
		$this->assertSame(1, $this->rowCount('chunk', 10));
	}

	public function testExpiredLeaseAloneRejectsTheWriteEvenWithoutAReclaim(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w, 'w1', 1);
		sleep(2);
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $this->write($w, $c)));
		$this->assertFalse((bool)$w->query("SELECT aps_search.heartbeat({$c['job_id']}, {$c['lease_epoch']})")->fetchColumn());
	}

	public function testHeartbeatExtendsAValidLease(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w, 'w1', 2);
		$this->assertTrue((bool)$w->query("SELECT aps_search.heartbeat({$c['job_id']}, {$c['lease_epoch']}, 900)")->fetchColumn());
		sleep(3);
		$this->write($w, $c);
		$this->assertSame(1, $this->rowCount('chunk', 10));
	}

	public function testWrongEtagAndHashAreRejected(): void {
		$this->seedFile(10, 'e1');
		$w = $this->worker();
		$c = $this->claim($w);
		$this->app()->exec("UPDATE aps_search.job SET claimed_hash = 'known-hash' WHERE id = {$c['job_id']}");
		$this->assertStringContainsString('etag_mismatch', $this->failure(fn () => $this->write($w, $c, ['etag' => 'other'])));
		$this->assertStringContainsString('hash_mismatch', $this->failure(fn () => $this->write($w, $c, ['hash' => 'different'])));
		$this->assertSame(0, $this->rowCount('page'));
		$this->write($w, $c, ['hash' => 'known-hash']);
		$this->assertSame(1, $this->rowCount('page', 10));
	}

	public function testFileChangedAfterClaimRejectsTheOldResult(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		$this->app()->exec("UPDATE aps_search.file_state SET etag = 'e2' WHERE file_id = 10");
		$this->assertStringContainsString('etag_mismatch', $this->failure(fn () => $this->write($w, $c)));
		$this->assertSame(0, $this->rowCount('page'));
	}

	public function testPayloadCapsAreEnforced(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		$many = json_encode(array_fill(0, 2001, ['page_no' => 1, 'ord' => 0, 'text' => 'x']));
		$this->assertStringContainsString('payload_too_large', $this->failure(fn () => $this->write($w, $c, ['chunks' => $many])));
		$long = json_encode([['page_no' => 1, 'ord' => 0, 'text' => str_repeat('a', 20001)]]);
		$this->assertStringContainsString('payload_too_large', $this->failure(fn () => $this->write($w, $c, ['chunks' => $long])));
		$this->assertSame(0, $this->rowCount('chunk'));
	}

	public function testConcurrencyCapIsFourActiveClaimsPerWorker(): void {
		for ($i = 1; $i <= 6; $i++) {
			$this->seedFile($i);
		}
		$w = $this->worker();
		$s = $w->prepare('SELECT count(*) FROM aps_search.claim_job(?, 10, ?, 900)');
		$s->execute(['text', 'w1']);
		$this->assertSame(4, (int)$s->fetchColumn());
		$s->execute(['text', 'w1']);
		$this->assertSame(0, (int)$s->fetchColumn());
		$s->execute(['text', 'w2']);
		$this->assertSame(2, (int)$s->fetchColumn());
	}

	public function testGlobalLeaseCapStopsWorkerIdRenaming(): void {
		for ($i = 1; $i <= 20; $i++) {
			$this->seedFile($i);
		}
		$w = $this->worker();
		$got = 0;
		for ($k = 1; $k <= 8; $k++) {
			$s = $w->prepare('SELECT count(*) FROM aps_search.claim_job(?, 4, ?, 900)');
			$s->execute(['text', "w$k"]);
			$got += (int)$s->fetchColumn();
		}
		$this->assertSame(16, $got);
	}

	public function testClaimDoesNotPinTheStoredHashOfAnOlderVersion(): void {
		$this->seedFile(10, 'e1', 'old-version-hash');
		$w = $this->worker();
		$this->write($w, $this->claim($w), ['hash' => 'new-version-hash']);
		$this->assertSame(1, $this->rowCount('page', 10));
	}

	public function testFailJobRetryThenSkip(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		$w->prepare('SELECT aps_search.fail_job(?, ?, ?, true)')->execute([$c['job_id'], $c['lease_epoch'], 'timeout']);
		$this->assertNull($this->claim($w), 'backoff keeps the job out of reach');
		$this->app()->exec('UPDATE aps_search.job SET not_before = now()');
		$c2 = $this->claim($w);
		$this->assertSame(2, $c2['lease_epoch']);
		$w->prepare('SELECT aps_search.fail_job(?, ?, ?, false)')->execute([$c2['job_id'], $c2['lease_epoch'], 'encrypted']);
		$st = self::connect('nextcloud', 'dev', self::$dbName)->query('SELECT text_state, skip_reason FROM aps_search.file_state WHERE file_id = 10')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'skipped', 'skip_reason' => 'encrypted'], $st);
		$this->assertStringContainsString('invalid reason', $this->failure(fn () => $w->exec("SELECT aps_search.fail_job(1, 1, 'free text', false)")));
	}

	public function testUnsafeIsAValidSkipReasonAndFreeTextStillIsNot(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		$w->prepare('SELECT aps_search.fail_job(?, ?, ?, false)')->execute([$c['job_id'], $c['lease_epoch'], 'unsafe']);
		$st = self::connect('nextcloud', 'dev', self::$dbName)->query('SELECT text_state, skip_reason FROM aps_search.file_state WHERE file_id = 10')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'skipped', 'skip_reason' => 'unsafe'], $st);
		$this->assertStringContainsString('invalid reason', $this->failure(fn () => $w->exec("SELECT aps_search.fail_job(1, 1, 'zip bomb', false)")));
	}

	public function testAJobThatKeepsLosingItsLeaseIsSkippedInsteadOfReclaimedForever(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$this->claim($w);
		$this->app()->exec("UPDATE aps_search.job SET attempts = 5, lease_until = now() - interval '1 minute'");
		$this->assertNull($this->claim($w));
		$this->assertSame(0, $this->rowCount('job'));
		$st = self::connect('nextcloud', 'dev', self::$dbName)->query('SELECT text_state, skip_reason FROM aps_search.file_state WHERE file_id = 10')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'skipped', 'skip_reason' => 'internal'], $st);
	}

	public function testAFreshJobBelowTheAttemptCapIsStillClaimed(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$this->claim($w);
		$this->app()->exec("UPDATE aps_search.job SET attempts = 4, lease_until = now() - interval '1 minute'");
		$this->assertSame(10, $this->claim($w)['file_id']);
	}

	// ---- H1: erase vs. an in-flight worker ----

	public function testClaimEraseThenWriteResultLeavesNoRows(): void {
		$this->seedFile(10);
		$this->app()->exec("INSERT INTO aps_search.file_acl (file_id, principals) VALUES (10, '{\"u:a\"}')");
		$w = $this->worker();
		$c = $this->claim($w);
		(new IndexEraser(new IndexDb($this->connection($this->app()))))->erase(10, 'excluded');
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $this->write($w, $c)));
		foreach (['page', 'chunk', 'file_acl', 'file_meta', 'job'] as $t) {
			$this->assertSame(0, $this->rowCount($t, 10), $t);
		}
		$st = self::connect('nextcloud', 'dev', self::$dbName)->query('SELECT text_state, skip_reason FROM aps_search.file_state WHERE file_id = 10')->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(['text_state' => 'skipped', 'skip_reason' => 'excluded'], $st);
	}

	public function testEraseDeletesIndexedDataAndASkippedFileIsNeverClaimedOrWritten(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$this->write($w, $this->claim($w));
		$this->assertSame(1, $this->rowCount('chunk', 10));
		(new IndexEraser(new IndexDb($this->connection($this->app()))))->erase(10, 'excluded');
		$this->assertSame(0, $this->rowCount('page', 10));
		$this->assertSame(0, $this->rowCount('chunk', 10));
		// A stray job for a skipped file is dropped by claim_job, not served.
		$this->app()->exec("INSERT INTO aps_search.job (lane, file_id, idem_key) VALUES ('text', 10, 'stray')");
		$this->assertNull($this->claim($w));
		$this->assertSame(0, $this->rowCount('job'));
	}

	// ---- H2: row locks ----

	public function testWriteResultWaitsForAnUncommittedEraseThenFailsStale(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		$e = $this->app();
		$e->beginTransaction();
		$e->exec("SELECT aps_search.erase_file(10, 'excluded')");        // holds the job row lock, uncommitted
		$w->exec("SET lock_timeout = '300ms'");
		$this->assertStringContainsString('lock timeout', $this->failure(fn () => $this->write($w, $c)), 'write_result must queue behind the lock');
		$e->commit();
		$w->exec("SET lock_timeout = '5s'");
		$this->assertStringContainsString('stale_lease', $this->failure(fn () => $this->write($w, $c)));
		$this->assertSame(0, $this->rowCount('chunk', 10));
	}

	public function testClaimJobSkipsRowsLockedByAnInFlightErase(): void {
		$this->seedFile(10);
		$e = $this->app();
		$e->beginTransaction();
		$e->exec("SELECT aps_search.erase_file(10, 'excluded')");
		$this->assertNull($this->claim($this->worker()), 'SKIP LOCKED: the locked job is not handed out');
		$e->rollBack();
		$this->assertNotNull($this->claim($this->worker()));
	}

	public function testRowLockOnAnExpiredJobBlocksAConcurrentReclaim(): void {
		$this->seedFile(10);
		$first = $this->claim($this->worker(), 'w1', 1);
		sleep(2);
		$holder = $this->app();                      // stands in for the FOR UPDATE that write_result and erase take first
		$holder->beginTransaction();
		$holder->prepare('SELECT 1 FROM aps_search.job WHERE id = ? FOR UPDATE')->execute([$first['job_id']]);
		$this->assertNull($this->claim($this->worker(), 'w2'), 'a locked expired job cannot be reclaimed mid-write');
		$holder->rollBack();
		$this->assertSame(2, $this->claim($this->worker(), 'w2')['lease_epoch']);
	}
}
