<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Status\Reindexer;
use OCA\BuscadorDocumental\Status\StatusReport;
use OCA\BuscadorDocumental\Worker\ClaimGuard;

/** S3 phase 3: pause stops new claims in the database itself; status and reindex report and queue numbers only. */
class PauseStatusTest extends DbTestCase {
	private function db(): IndexDb {
		return new IndexDb($this->connection($this->app()));
	}

	public function testPausedMeansNoNewClaimsAndResumeRestoresThem(): void {
		$this->seedFile(10);
		$s = new Settings($this->db());
		$s->setPaused(true);
		$this->assertNull($this->claim($this->worker()), 'paused: nothing is handed out');
		$s->setPaused(false);
		$this->assertSame(10, $this->claim($this->worker())['file_id']);
	}

	public function testOnlyTheExactPausedValueStopsClaims(): void {
		$this->seedFile(10);
		$this->app()->exec("INSERT INTO aps_search.settings (key, value) VALUES ('paused', '0')");
		$this->assertNotNull($this->claim($this->worker()));
	}

	public function testWorkInFlightWhenPausedStillHeartbeatsAndCompletes(): void {
		$this->seedFile(10);
		$w = $this->worker();
		$c = $this->claim($w);
		(new Settings($this->db()))->setPaused(true);
		$hb = $w->prepare('SELECT aps_search.heartbeat(?, ?, 900)');
		$hb->execute([$c['job_id'], $c['lease_epoch']]);
		$this->assertTrue((bool)$hb->fetchColumn());
		$this->write($w, $c);
		$this->assertSame(1, $this->rowCount('page', 10), 'a result already being produced is accepted');
	}

	public function testTheClaimGuardReportsPause(): void {
		$g = new ClaimGuard($this->db());
		$this->assertFalse($g->isPaused());
		(new Settings($this->db()))->setPaused(true);
		$this->assertTrue($g->isPaused());
	}

	public function testStatusReportsNumbersAndCodesOnly(): void {
		$app = $this->app();
		$this->seedFile(10);
		$this->seedFile(11);
		$w = $this->worker();
		$this->claim($w);
		$app->exec("INSERT INTO aps_search.file_state (file_id, text_state, skip_reason) VALUES (20, 'skipped', 'encrypted'), (21, 'skipped', 'encrypted'), (22, 'skipped', 'unsafe')");
		$app->exec("INSERT INTO aps_search.outbox (file_id, kind, attempts) VALUES (1, 'file', 0), (2, 'file', 5), (3, 'acl', 0)");
		$app->exec("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() - interval '2 hours' WHERE file_id = 11");
		$app->exec("INSERT INTO aps_search.reconcile_run (phase, counts, finished_at) VALUES ('done', '{\"missing\": 3}', now())");
		$snap = (new StatusReport($this->db()))->snapshot();
		$this->assertSame('opt_in', $snap['mode']);
		$this->assertFalse($snap['paused']);
		$this->assertSame(['queued' => 1, 'claimed' => 1], $snap['jobs']);
		$this->assertSame(['encrypted' => 2, 'unsafe' => 1], $snap['skipped']);
		$this->assertSame(['pending' => 3, 'parked' => 1], $snap['outbox']);
		$this->assertEqualsWithDelta(7200, $snap['acl_backlog_seconds'], 5);
		$this->assertSame(['missing' => 3], $snap['last_reconcile']['counts']);
		$this->assertSame(2, $snap['states']['pending']);
	}

	public function testStatusOnAnEmptyIndexHasNoBacklogAndNoReconcile(): void {
		$snap = (new StatusReport($this->db()))->snapshot();
		$this->assertNull($snap['acl_backlog_seconds']);
		$this->assertNull($snap['last_reconcile']);
		$this->assertSame(['pending' => 0, 'parked' => 0], $snap['outbox']);
	}

	public function testReindexQueuesOneFileOrEveryKnownFileWithoutDuplicates(): void {
		$db = $this->db();
		$app = $this->app();
		$app->exec("INSERT INTO aps_search.file_state (file_id, text_state) VALUES (1, 'indexed'), (2, 'indexed'), (3, 'skipped')");
		$r = new Reindexer($db);
		$this->assertSame(1, $r->enqueue(2));
		$this->assertSame(0, $r->enqueue(2), 'already queued');
		$this->assertSame(2, $r->enqueueAll(), 'the other two; the one already queued is not repeated');
		$this->assertSame(3, $this->rowCount('outbox'));
	}

	public function testTheClaimJobCopiesInTheTwoScriptsDoNotDrift(): void {
		$body = static function (string $file): string {
			$sql = file_get_contents(__DIR__ . '/../../../lib/Migration/sql/' . $file);
			preg_match('/FUNCTION aps_search\.claim_job.*?\n\$\$;/s', $sql, $m);
			return preg_replace('/\s+/', ' ', preg_replace('/^\s*--.*$/m', '', preg_replace('/^CREATE (OR REPLACE )?/', '', $m[0])));
		};
		$this->assertSame($body('002_functions.sql'), $body('004_s3.sql'));
	}
}
