<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Ops\WorkWindow;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Status\StatusReport;

class StatusEtaTest extends DbTestCase {
	private function report(): StatusReport {
		$db = new IndexDb($this->connection($this->app()));
		return new StatusReport($db, new WorkWindow(new Settings($db), new AuditLog($db, new Pseudonyms($db))));
	}

	public function testATransitionToIndexedStampsTheCompletionTimeAndALaterTouchDoesNot(): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES (1, 'e', 'pending')");
		$this->assertNull($a->query('SELECT indexed_at FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
		$a->exec("UPDATE aps_search.file_state SET text_state = 'indexed' WHERE file_id = 1");
		$first = $a->query('SELECT indexed_at FROM aps_search.file_state WHERE file_id = 1')->fetchColumn();
		$this->assertNotNull($first);
		usleep(20000);
		$a->exec("UPDATE aps_search.file_state SET updated_at = now(), etag = 'e2' WHERE file_id = 1");
		$this->assertSame($first, $a->query('SELECT indexed_at FROM aps_search.file_state WHERE file_id = 1')->fetchColumn(), 'events that touch the row are not completions');
		$a->exec("UPDATE aps_search.file_state SET text_state = 'pending' WHERE file_id = 1");
		usleep(20000);
		$a->exec("UPDATE aps_search.file_state SET text_state = 'indexed' WHERE file_id = 1");
		$this->assertNotSame($first, $a->query('SELECT indexed_at FROM aps_search.file_state WHERE file_id = 1')->fetchColumn(), 'a new completion is stamped');
		$a->exec("UPDATE aps_search.file_state SET vec_state = 'embedded' WHERE file_id = 1");
		$this->assertNotNull($a->query('SELECT embedded_at FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
	}

	public function testTheTextEtaComesFromTheObservedRateAndTheQueue(): void {
		$a0 = $this->app();
		$a0->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, indexed_at) SELECT g, 'e', 'indexed', now() - make_interval(mins => 5 + g) FROM generate_series(1, 40) g");        // 40 in the last hour
		$a0->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, indexed_at) SELECT g, 'e', 'indexed', now() - make_interval(mins => 65 + g - 41) FROM generate_series(41, 80) g");  // 40 in the hour before
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) SELECT g, 'e', 'pending' FROM generate_series(101, 180) g");
		$a->exec("INSERT INTO aps_search.job (lane, file_id, idem_key) SELECT 'text', g, g || ':e' FROM generate_series(101, 180) g");    // 80 waiting
		$e = $this->report()->snapshot()['eta']['text'];
		$this->assertSame('ok', $e['state']);
		$this->assertSame(80, $e['remaining']);
		$this->assertEqualsWithDelta(40.0, $e['rate_per_hour'], 1e-6);
		$this->assertEqualsWithDelta(7200, $e['eta_seconds'], 7200 * 0.05);
	}

	public function testPausedAndThinSamplesAreReportedAsSuch(): void {
		$this->seedFile(1);
		$this->assertSame('unknown', $this->report()->snapshot()['eta']['text']['state']);
		$this->app()->exec("INSERT INTO aps_search.settings (key, value) VALUES ('paused', '1')");
		$this->assertSame('paused', $this->report()->snapshot()['eta']['text']['state']);
	}

	public function testTheVectorSideCountsEmbeddingsAndTheWindow(): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation(1, 'p', 'm', 'd', 3, 'f16', '', '', 512)");
		$a->exec('SELECT aps_search.mark_generation_ready(1)');
		$a->exec('SELECT aps_search.activate_generation(1)');
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, vec_state, indexed_at, embedded_at) SELECT g, 'e', 'indexed', 'embedded', now() - interval '3 hours', now() - make_interval(mins => g) FROM generate_series(1, 30) g");
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, vec_state, indexed_at) SELECT g, 'e', 'indexed', 'pending', now() - interval '3 hours' FROM generate_series(31, 90) g");
		$a->exec("INSERT INTO aps_search.settings (key, value) VALUES ('vec_coverage', '0.3333')");
		$e = $this->report()->snapshot()['eta']['vec'];
		$this->assertSame('ok', $e['state']);
		$this->assertEqualsWithDelta(60, $e['remaining'], 2);
		$h = (int)gmdate('G');
		$a->exec(sprintf("INSERT INTO aps_search.settings (key, value) VALUES ('work_window', '%02d:00-%02d:00'), ('work_tz', 'UTC')", ($h + 5) % 24, ($h + 7) % 24));
		$e = $this->report()->snapshot()['eta']['vec'];
		$this->assertSame('window_closed', $e['state']);
		$this->assertGreaterThan(3600, $e['opens_in_seconds']);
	}

	public function testSnapshotCarriesWorkerLivenessAgeAndSize(): void {
		$this->claimAs('w1');
		$s = $this->report()->snapshot();
		$this->assertArrayHasKey('text', $s['workers']);
		$this->assertLessThan(30, $s['workers']['text']);
		$this->assertGreaterThan(0, $s['schema_bytes']);
		$this->assertNull($s['last_reconcile_age_seconds']);
	}

	private function claimAs(string $who): void {
		$this->worker()->prepare('SELECT * FROM aps_search.claim_job(?, 1, ?, 900)')->execute(['text', $who]);
	}
}
