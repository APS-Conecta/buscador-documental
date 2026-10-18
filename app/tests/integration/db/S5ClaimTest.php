<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use PDO;

/** S5 phase 1: the night window, the concurrency caps and worker liveness, all enforced by claim_job. */
class S5ClaimTest extends DbTestCase {
	private function set(string $k, string $v): void {
		$this->app()->prepare('INSERT INTO aps_search.settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value')->execute([$k, $v]);
	}

	/** A window that contains the present moment in UTC, and one that does not. */
	private function around(): array {
		$h = (int)gmdate('G');
		$in = sprintf('%02d:00-%02d:00', ($h + 23) % 24, ($h + 2) % 24);
		$out = sprintf('%02d:00-%02d:00', ($h + 5) % 24, ($h + 7) % 24);
		return [$in, $out];
	}

	private function claimLane(PDO $w, string $lane, string $who = 'w1'): ?array {
		$s = $w->prepare('SELECT * FROM aps_search.claim_job(?, 1, ?, 900)');
		$s->execute([$lane, $who]);
		$r = $s->fetch(PDO::FETCH_ASSOC);
		return $r === false ? null : $r;
	}

	private function vecJob(int $file): void {
		$this->seedFile($file);
		$this->app()->exec("UPDATE aps_search.file_state SET text_state = 'indexed' WHERE file_id = $file");
		$this->app()->exec("DELETE FROM aps_search.job WHERE file_id = $file");
		$this->app()->exec("INSERT INTO aps_search.job (lane, file_id, idem_key, gen) VALUES ('vec', $file, '$file:e1:1', 1)");
		$this->app()->exec("INSERT INTO aps_search.embedding_profile (gen, profile_id, model, digest, dim, max_tokens, state) VALUES (1, 'p', 'm', 'd', 3, 512, 'active') ON CONFLICT DO NOTHING");
	}

	public function testSchemaIsFour(): void {
		$this->assertSame(5, (int)$this->worker()->query('SELECT aps_search.schema_version()')->fetchColumn());
	}

	public function testWithoutSettingsNothingChanges(): void {
		$this->seedFile(1);
		$this->assertNotNull($this->claimLane($this->worker(), 'text'));
		$this->vecJob(2);
		$this->assertNotNull($this->claimLane($this->worker(), 'vec'));
	}

	public function testTheWindowHoldsBackTheVectorLaneOutsideItAndOnlyThat(): void {
		[$in, $out] = $this->around();
		$this->seedFile(1);
		$this->vecJob(2);
		$this->set('work_window', $out);
		$this->assertNull($this->claimLane($this->worker(), 'vec'), 'outside the window');
		$this->assertNotNull($this->claimLane($this->worker(), 'text'), 'the text lane keeps running by day');
		$this->set('work_window', $in);
		$this->assertNotNull($this->claimLane($this->worker(), 'vec'), 'inside the window');
	}

	public function testLanesAllHoldsBackTheTextLaneToo(): void {
		[, $out] = $this->around();
		$this->seedFile(1);
		$this->set('work_window', $out);
		$this->set('work_window_lanes', 'all');
		$this->assertNull($this->claimLane($this->worker(), 'text'));
	}

	public function testAWindowThatCrossesMidnightAndATimeZoneWork(): void {
		$this->seedFile(1);
		$this->set('work_window_lanes', 'all');
		$this->set('work_window', '22:00-06:00');
		$this->set('work_tz', 'Pacific/Kiritimati');                         // UTC+14: pick the zone, then the window that contains its present time
		$hour = (int)(new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Kiritimati')))->format('G');
		$this->set('work_window', sprintf('%02d:00-%02d:00', ($hour + 23) % 24, ($hour + 2) % 24));
		$this->assertNotNull($this->claimLane($this->worker(), 'text'), 'inside in that zone');
		$this->set('work_window', sprintf('%02d:00-%02d:00', ($hour + 5) % 24, ($hour + 7) % 24));
		$this->assertNull($this->claimLane($this->worker(), 'text'), 'outside in that zone');
	}

	public function testPauseWinsAndBadSettingsAreIgnored(): void {
		[$in] = $this->around();
		$this->seedFile(1);
		$this->set('work_window', $in);
		$this->set('paused', '1');
		$this->assertNull($this->claimLane($this->worker(), 'text'));
		$this->set('paused', '0');
		foreach (['25:00-26:00', 'tonight', '', '1:00-2:00'] as $bad) {
			$this->set('work_window', $bad);
			$this->set('work_window_lanes', 'all');
			$this->assertNotNull($this->claimLane($this->worker(), 'text', 'w' . crc32($bad)), "an unusable window ($bad) never blocks the index");
			$this->app()->exec('UPDATE aps_search.job SET lease_until = NULL, claimed_by = NULL');
		}
		$this->set('work_window', '01:00-05:00');
		$this->set('work_tz', 'Not/AZone');
		$this->assertNotNull($this->claimLane($this->worker(), 'text'), 'an unknown time zone is ignored, the work runs');
	}

	public function testTheCapsComeFromSettingsAndJunkFallsBack(): void {
		for ($i = 1; $i <= 6; $i++) {
			$this->seedFile($i);
		}
		$w = $this->worker();
		$this->set('cap_text', '2');
		$this->assertNotNull($this->claimLane($w, 'text'));
		$this->assertNotNull($this->claimLane($w, 'text'));
		$this->assertNull($this->claimLane($w, 'text'), 'two per worker now');
		$this->set('cap_text', 'many');
		$this->app()->exec('UPDATE aps_search.job SET lease_until = NULL, claimed_by = NULL');
		$got = 0;
		while ($this->claimLane($w, 'text') !== null) {
			$got++;
		}
		$this->assertSame(4, $got, 'junk means the default of four');
		$this->set('cap_text', '0');
		$this->app()->exec('UPDATE aps_search.job SET lease_until = NULL, claimed_by = NULL');
		$this->assertNotNull($this->claimLane($w, 'text'), 'zero would stop the index: it is not a cap');
	}

	public function testEveryClaimRecordsThatTheWorkerIsAlivePausedOrNot(): void {
		$w = $this->worker();
		$this->claimLane($w, 'text', 'worker-a');
		$this->set('paused', '1');
		$this->claimLane($w, 'vec', 'worker-a');
		$rows = $this->app()->query("SELECT worker || ':' || lane FROM aps_search.worker_seen ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame(['worker-a:text', 'worker-a:vec'], $rows);
		$old = $this->app()->query("SELECT at FROM aps_search.worker_seen WHERE lane = 'text'")->fetchColumn();
		usleep(20000);
		$this->claimLane($w, 'text', 'worker-a');
		$new = $this->app()->query("SELECT at FROM aps_search.worker_seen WHERE lane = 'text'")->fetchColumn();
		$this->assertGreaterThan($old, $new);
		$this->assertSame(2, (int)$this->app()->query('SELECT count(*) FROM aps_search.worker_seen')->fetchColumn(), 'one row per worker and lane');
	}

	public function testTheWorkerCannotReadOrWriteTheLivenessTableDirectly(): void {
		$this->assertStringContainsString('permission denied', $this->failure(fn () => $this->worker()->exec('SELECT * FROM aps_search.worker_seen')));
		$this->assertStringContainsString('permission denied', $this->failure(fn () => $this->worker()->exec("INSERT INTO aps_search.worker_seen (worker, lane) VALUES ('x', 'text')")));
	}

	public function testTheTextBranchStillBehavesLikeBefore(): void {
		$this->seedFile(1);
		$w = $this->worker();
		$c = $this->claimLane($w, 'text');
		$this->assertSame('e1', $c['etag']);
		$this->assertNull($this->claimLane($w, 'text', 'w2'), 'a claimed job is not handed out twice');
	}

	public function testOnlyRealLanesAreRecordedAndAMalformedWorkerNameIsRefused(): void {
		$w = $this->worker();
		$this->assertNull($this->claimLane($w, "x\n"), 'an unknown lane gets nothing');
		$this->assertNull($this->claimLane($w, 'weird'));
		$this->assertSame(0, (int)$this->app()->query('SELECT count(*) FROM aps_search.worker_seen')->fetchColumn(), 'and leaves no trace');
		foreach (["bad name", "w\n", str_repeat('x', 65), 'ñandú'] as $bad) {
			$this->assertStringContainsString('worker id', $this->failure(fn () => $this->claimLane($w, 'text', $bad)), json_encode($bad));
		}
		$this->assertSame(0, (int)$this->app()->query('SELECT count(*) FROM aps_search.worker_seen')->fetchColumn());
	}

	public function testTheLivenessTableCannotBeFilledByInventingWorkerNames(): void {
		$w = $this->worker();
		for ($i = 0; $i < 210; $i++) {
			$this->claimLane($w, 'text', 'w' . $i);
		}
		$this->assertLessThanOrEqual(200, (int)$this->app()->query('SELECT count(*) FROM aps_search.worker_seen')->fetchColumn());
		$this->app()->exec("UPDATE aps_search.worker_seen SET at = now() - interval '1 hour' WHERE worker = 'w0'");
		$this->claimLane($w, 'text', 'w0');
		$this->assertLessThan(60, (int)$this->app()->query("SELECT extract(epoch FROM now() - at) FROM aps_search.worker_seen WHERE worker = 'w0'")->fetchColumn(), 'a known worker is still refreshed when the table is full');
	}

	public function testAHeartbeatAlsoProvesTheWorkerIsAliveDuringALongJob(): void {
		$this->seedFile(1);
		$w = $this->worker();
		$c = $this->claimLane($w, 'text', 'slow-worker');
		$this->app()->exec("UPDATE aps_search.worker_seen SET at = now() - interval '30 minutes'");
		$w->prepare('SELECT aps_search.heartbeat(?, ?, 900)')->execute([$c['job_id'], $c['lease_epoch']]);
		$this->assertLessThan(60, (int)$this->app()->query("SELECT extract(epoch FROM now() - at) FROM aps_search.worker_seen WHERE worker = 'slow-worker' AND lane = 'text'")->fetchColumn());
	}
}
