<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Reconcile\Reconciler;
use OCA\BuscadorDocumental\Scope\ScopeRules;

/** A-OPS-3: the nightly reconciliation repairs what events missed, set-based and resumable. */
class ReconcileTest extends DbTestCase {
	private int $statements = 0;

	/**
	 * Home storage 1: 10 files, 11 files/top, 12 files/top/mid, 13 a.pdf, 17 c.pdf, 14 files/other, 15 b.pdf, 18 d.pdf (under 11),
	 * 16 trashed. Storage 2 (groupfolder): 20 files, 21 team.pdf. Tag rag-excluded (id 5) mapped as the tests need.
	 */
	private function fixture(): void {
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('DROP TABLE IF EXISTS oc_filecache, oc_storages, oc_group_folders, oc_mimetypes, oc_systemtag, oc_systemtag_object_mapping');
		$admin->exec('CREATE TABLE oc_mimetypes (id bigint primary key, mimetype text)');
		$admin->exec("INSERT INTO oc_mimetypes VALUES (1, 'httpd/unix-directory'), (2, 'application/pdf')");
		$admin->exec('CREATE TABLE oc_storages (numeric_id bigint primary key, id text)');
		$admin->exec("INSERT INTO oc_storages VALUES (1, 'home::alice'), (2, 'local::/var/www/html/data/__groupfolders/1/')");
		$admin->exec('CREATE TABLE oc_filecache (fileid bigint primary key, storage bigint, path text, parent bigint, etag text, mimetype bigint default 2, size bigint default 10)');
		$admin->exec('CREATE INDEX ON oc_filecache (parent)');
		$admin->exec("INSERT INTO oc_filecache (fileid, storage, path, parent, etag, mimetype) VALUES
			(10, 1, 'files', -1, 'x', 1), (11, 1, 'files/top', 10, 'x', 1), (12, 1, 'files/top/mid', 11, 'x', 1),
			(13, 1, 'files/top/mid/a.pdf', 12, 'e13', 2), (17, 1, 'files/top/mid/c.pdf', 12, 'e17', 2), (18, 1, 'files/top/d.pdf', 11, 'e18', 2),
			(14, 1, 'files/other', 10, 'x', 1), (15, 1, 'files/other/b.pdf', 14, 'e15', 2), (16, 1, 'files_trashbin/files/old.pdf.d1', -1, 'e16', 2),
			(20, 2, 'files', -1, 'x', 1), (21, 2, 'files/team.pdf', 20, 'e21', 2)");
		$admin->exec('CREATE TABLE oc_systemtag (id bigint primary key, name text)');
		$admin->exec('CREATE TABLE oc_systemtag_object_mapping (objectid text, objecttype text, systemtagid bigint)');
		$admin->exec("INSERT INTO oc_systemtag VALUES (5, 'rag-excluded'), (6, 'urgente')");
		$admin->exec('GRANT SELECT ON oc_filecache, oc_storages, oc_mimetypes, oc_systemtag, oc_systemtag_object_mapping TO nextcloud');
	}

	private function state(int $id, string $etag, string $text = 'indexed', ?string $reason = null, ?int $storage = 1): void {
		$a = $this->app();
		$a->prepare('INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (?, ?, ?, ?)')->execute([$id, $etag, $text, $reason]);
		$a->prepare("INSERT INTO aps_search.file_meta (file_id, name, storage_id) VALUES (?, 'f', ?)")->execute([$id, $storage]);
	}

	private function reconciler(array $folderRules = [11], bool $groupfolders = false, int $window = 4, ?\OCP\IUserManager $users = null): array {
		$this->fixture();
		$conn = $this->connection($this->app());
		$counting = $this->createMock(\OCP\IDBConnection::class);
		foreach (['executeQuery', 'executeStatement'] as $m) {
			$counting->method($m)->willReturnCallback(function (string $sql, array $p = []) use ($conn, $m) {
				$this->statements++;
				return $conn->$m($sql, $p);
			});
		}
		$db = new IndexDb($counting);
		$rules = new ScopeRules($db, new AuditLog($db, new Pseudonyms($db)));
		foreach ($folderRules as $f) {
			$rules->addFolder($f, 'occ');
		}
		if ($groupfolders) {
			$rules->addGroupfolders('occ');
		}
		$db->execute("DELETE FROM aps_search.settings WHERE key = 'reconcile_now'");        // setting up the rules is not a change to react to
		return [new Reconciler($db, $rules, window: $window, users: $users), $db, $rules];
	}

	/** @return list<int> */
	private function queued(IndexDb $db): array {
		return array_map('intval', array_column($db->rows("SELECT file_id FROM aps_search.outbox WHERE processed_at IS NULL ORDER BY file_id"), 'file_id'));
	}

	private function finish(Reconciler $r): array {
		$last = [];
		for ($i = 0; $i < 50; $i++) {
			$last = $r->runOnce(30, '2026-10-17');
			if ($last['finished']) {
				return $last;
			}
		}
		$this->fail('the reconciliation did not finish');
	}

	public function testItRepairsEverythingTheEventsMissed(): void {
		[$r, $db] = $this->reconciler();
		// scope: folder 11 => files 13, 17, 18. The outbox is empty: every event was lost.
		$this->state(13, 'OLD-ETAG');                         // edited, edit event lost
		//  17 and 18: created, creation event lost => no state at all
		$this->state(15, 'e15');                              // moved out of the rule (15 is under "other"), move event lost
		$this->state(99, 'e99');                              // deleted, delete event lost
		$this->state(21, 'e21', storage: 2);                  // out of scope too (no groupfolder rule)
		$done = $this->finish($r);
		$this->assertSame([13, 15, 17, 18, 21, 99], $this->queued($db));
		$this->assertTrue($done['finished']);
		$c = $done['counts'];
		$this->assertSame(3, $c['forward']);
		$this->assertSame(3, $c['reverse']);
	}

	public function testAFileThatIsUpToDateIsLeftAlone(): void {
		[$r, $db] = $this->reconciler();
		foreach ([13, 17, 18] as $id) {
			$this->state($id, "e$id");
		}
		$this->finish($r);
		$this->assertSame([], $this->queued($db));
	}

	public function testATransferredFileIsFoundByItsStorage(): void {
		[$r, $db] = $this->reconciler();
		foreach ([13, 17] as $id) {
			$this->state($id, "e$id");
		}
		$this->state(18, 'e18', storage: 77);                 // file_meta says another storage: ownership moved
		$this->finish($r);
		$this->assertSame([18], $this->queued($db));
	}

	public function testExcludedFilesAreNotRequeuedEveryNightButAMissedUntagIs(): void {
		[$r, $db] = $this->reconciler();
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec("INSERT INTO oc_systemtag_object_mapping VALUES ('12', 'files', 5)");        // tag on folder 12: 13 and 17 excluded
		$this->state(13, 'e13', 'skipped', 'excluded');       // correctly erased
		$this->state(17, 'e17', 'skipped', 'excluded');
		$this->state(18, 'e18', 'skipped', 'excluded');       // 18 is NOT under 12: the untag event was lost
		$this->finish($r);
		$this->assertSame([18], $this->queued($db), 'only the one that is no longer excluded');
	}

	public function testAnIndexedFileUnderANewTagIsFoundToo(): void {
		[$r, $db] = $this->reconciler();
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec("INSERT INTO oc_systemtag_object_mapping VALUES ('17', 'files', 5)");
		foreach ([13, 17, 18] as $id) {
			$this->state($id, "e$id");
		}
		$this->finish($r);
		$this->assertSame([17], $this->queued($db), 'the tag event was lost: the processor will erase it');
	}

	public function testAnIndexedFileUnderACaseVariantOfTheTagIsFoundToo(): void {
		[$r, $db] = $this->reconciler();
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec("UPDATE oc_systemtag SET name = 'RAG-excluded' WHERE id = 5");             // Nextcloud allows a case-only rename
		$admin->exec("INSERT INTO oc_systemtag_object_mapping VALUES ('12', 'files', 5)");
		foreach ([13, 17, 18] as $id) {
			$this->state($id, "e$id");
		}
		$this->finish($r);
		$this->assertSame([13, 17], $this->queued($db), 'indexed content under the tagged folder is queued for erasure');
	}

	public function testOtherTagsDoNotExclude(): void {
		[$r, $db] = $this->reconciler();
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec("INSERT INTO oc_systemtag_object_mapping VALUES ('12', 'files', 6), ('12', 'calendar', 5)");
		foreach ([13, 17, 18] as $id) {
			$this->state($id, "e$id");
		}
		$this->finish($r);
		$this->assertSame([], $this->queued($db));
	}

	public function testPermanentSkipsWithTheSameEtagAreNotRetriedEveryNight(): void {
		[$r, $db] = $this->reconciler();
		$this->state(13, 'e13', 'skipped', 'encrypted');
		$this->state(17, 'e17', 'skipped', 'unsafe');
		$this->state(18, 'e18');
		$this->finish($r);
		$this->assertSame([], $this->queued($db));
	}

	public function testAFileMovedBackIntoScopeAfterBeingErasedIsRequeued(): void {
		[$r, $db] = $this->reconciler();
		$this->state(13, 'e13', 'skipped', 'out_of_scope');
		$this->state(17, 'e17');
		$this->state(18, 'e18');
		$this->finish($r);
		$this->assertSame([13], $this->queued($db));
	}

	public function testTheTeamFolderRuleIsReconciledToo(): void {
		[$r, $db] = $this->reconciler([], true);
		$this->finish($r);
		$this->assertSame([21], $this->queued($db), 'the only team-folder file, missing from the index');
	}

	public function testRunningTwiceQueuesNothingNew(): void {
		[$r, $db] = $this->reconciler();
		$this->finish($r);
		$first = $this->queued($db);
		$this->finish($r);
		$this->assertSame($first, $this->queued($db));
		$this->assertSame(2, (int)$db->rows('SELECT count(*) AS n FROM aps_search.reconcile_run WHERE finished_at IS NOT NULL')[0]['n']);
	}

	public function testItResumesFromItsCursorAcrossInvocations(): void {
		[$r, $db] = $this->reconciler();
		$first = $r->runOnce(0, '2026-10-17');                    // zero budget: one step only
		$this->assertFalse($first['finished']);
		$mid = $db->rows('SELECT phase, cursor_file_id FROM aps_search.reconcile_run ORDER BY run_id DESC LIMIT 1')[0];
		$this->assertNotSame('', $mid['phase']);
		$done = $this->finish($r);
		$this->assertSame([13, 17, 18], $this->queued($db));
		$this->assertSame(1, (int)$db->rows('SELECT count(*) AS n FROM aps_search.reconcile_run')[0]['n'], 'one run, not one per invocation');
		$this->assertTrue($done['finished']);
	}

	public function testARunOlderThanThirtySixHoursIsAbandonedAndRestarted(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.reconcile_run (started_at, phase, cursor_file_id) VALUES (now() - interval '40 hours', 'forward', 5)");
		$r->runOnce(0, '2026-10-17');
		$old = $db->rows('SELECT counts, finished_at FROM aps_search.reconcile_run ORDER BY run_id LIMIT 1')[0];
		$this->assertNotNull($old['finished_at']);
		$this->assertSame(1, json_decode($old['counts'], true)['abandoned']);
		$this->assertSame(2, (int)$db->rows('SELECT count(*) AS n FROM aps_search.reconcile_run')[0]['n']);
	}

	public function testThePerNightCapSuspendsTheRunAndAnotherDayResumesIt(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.settings (key, value) VALUES ('reconcile_cap', '2')");
		$suspended = null;
		for ($i = 0; $i < 30 && $suspended === null; $i++) {
			$step = $r->runOnce(30, '2026-10-17');
			$suspended = ($step['suspended'] ?? false) ? $step : null;
		}
		$this->assertNotNull($suspended, 'the cap stops the run');
		$this->assertLessThanOrEqual(2 + 3, count($this->queued($db)), 'a window may overshoot by at most its own rows');
		$this->assertTrue($r->runOnce(30, '2026-10-17')['suspended'], 'same day: still suspended');
		$db->execute("UPDATE aps_search.settings SET value = '100' WHERE key = 'reconcile_cap'");
		$this->finish($r);
		$this->assertSame([13, 17, 18], array_values(array_intersect($this->queued($db), [13, 17, 18])));
	}

	public function testWeeklyAclRefreshMarksIndexedFilesDirtyOnlyWhenDue(): void {
		[$r, $db] = $this->reconciler();
		foreach ([13, 17, 18] as $id) {
			$this->state($id, "e$id");
		}
		$this->finish($r);
		$this->assertSame(3, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n'], 'first run: never done, so due');
		$db->execute('UPDATE aps_search.file_state SET acl_dirty = false, acl_dirty_since = NULL');
		$this->finish($r);
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n'], 'second run the same week: not due');
	}

	public function testHousekeepingPrunesTombstonesAuditAndOldRuns(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.tombstone (file_id, reason, purge_after) VALUES (1, 'trashed', now() - interval '1 day'), (2, 'trashed', now() + interval '10 days')");
		$db->execute("INSERT INTO aps_search.audit_log (at, actor, action) VALUES (now() - interval '91 days', 'x', 'scope.add')");
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind, processed_at) VALUES (1, 'file', now() - interval '8 days')");
		$this->finish($r);
		$this->assertSame([2], array_map('intval', array_column($db->rows('SELECT file_id FROM aps_search.tombstone'), 'file_id')));
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.audit_log WHERE at < now() - interval '90 days'")[0]['n']);
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.outbox WHERE processed_at < now() - interval '7 days'")[0]['n']);
	}

	public function testTheWorkIsSetBasedNotPerFile(): void {
		[$r] = $this->reconciler(window: 100);
		$this->statements = 0;
		$this->finish($r);
		$small = $this->statements;
		$this->assertLessThan(60, $small, 'a fixed handful of statements for a tiny instance');
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec("INSERT INTO oc_filecache (fileid, storage, path, parent, etag) SELECT g, 1, 'files/top/mid/g' || g || '.pdf', 12, 'e' || g FROM generate_series(1000, 1399) g");
		$this->statements = 0;
		$this->finish($r);
		$this->assertLessThan($small + 25, $this->statements, '400 more files add a few windows, not hundreds of per-file queries');
	}

	public function testItIsDueInsideTheWindowOncePerDayOrWhileARunIsOpen(): void {
		[$r, $db] = $this->reconciler();
		$in = new \DateTimeImmutable('2026-10-17 02:30:00');
		$out = new \DateTimeImmutable('2026-10-17 12:00:00');
		$this->assertTrue($r->due($in, '01:00-05:00'), 'inside the window, never run');
		$this->assertFalse($r->due($out, '01:00-05:00'), 'outside the window');
		$r->runOnce(0, '2026-10-17');                                           // opens a run
		$this->assertTrue($r->due($in, '01:00-05:00'), 'a run is open: keep going inside the window');
		$this->assertFalse($r->due($out, '01:00-05:00'), 'but never outside it');
		$this->finish($r);
		$db->execute("UPDATE aps_search.reconcile_run SET finished_at = '2026-10-17 01:50:00'");
		$this->assertFalse($r->due($in, '01:00-05:00'), 'already done today');
		$this->assertTrue($r->due(new \DateTimeImmutable('2026-10-18 02:30:00'), '01:00-05:00'), 'tomorrow night it is due again');
	}

	public function testEveryCounterIsPresentEvenWhenNothingHappened(): void {
		[$r] = $this->reconciler([]);                    // no rules: nothing is eligible, no window ever runs a pass
		$counts = $this->finish($r)['counts'];
		foreach (['eligible', 'forward', 'reverse', 'acl_marked', 'tombstones_purged', 'audit_pruned', 'outbox_pruned'] as $key) {
			$this->assertArrayHasKey($key, $counts, $key);
			$this->assertSame(0, $counts[$key], $key);
		}
	}

	public function testRemovalsAreNeverBlockedByTheCapOnAdditions(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.settings (key, value) VALUES ('reconcile_cap', '1')");
		$this->state(99, 'e99');                        // deleted behind Nextcloud's back
		$this->state(15, 'e15');                        // moved out of the rule
		// 13, 17, 18 are eligible but not indexed: three additions, far over the cap of 1
		for ($i = 0; $i < 20; $i++) {
			$step = $r->runOnce(30, '2026-10-17');
			if ($step['suspended'] ?? false) {
				break;
			}
		}
		$queued = $this->queued($db);
		$this->assertContains(99, $queued, 'a vanished file is queued although additions are capped');
		$this->assertContains(15, $queued, 'so is a file that left the scope');
	}

	public function testOnlyAdditionsCountTowardsTheCap(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.settings (key, value) VALUES ('reconcile_cap', '4')");        // above the 3 additions, below the 8 rows in all
		foreach ([91, 92, 93, 94, 95] as $gone) {
			$this->state($gone, "e$gone");              // five removals: more than the cap
		}
		$done = $this->finish($r);
		$this->assertSame(5, $done['counts']['reverse']);
		$this->assertSame(3, $done['counts']['queued_today'], 'only the three additions are counted, not the five removals');
	}

	// ---- S3 review fixes ----
	private function outsideTheWindow(): \DateTimeImmutable {
		return new \DateTimeImmutable('2026-10-17 12:00:00');
	}

	public function testChangingTheRulesMakesTheNextEvaluationDueAtOnce(): void {
		[$r, $db, $rules] = $this->reconciler();
		$this->finish($r);
		$this->assertFalse($r->due($this->outsideTheWindow(), '01:00-05:00'), 'finished today, outside the window');
		$id = $rules->addFolder(14, 'occ');
		$this->assertTrue($r->due($this->outsideTheWindow(), '01:00-05:00'), 'adding a rule: do not wait for tonight');
		$this->finish($r);
		$this->assertFalse($r->due($this->outsideTheWindow(), '01:00-05:00'), 'evaluated: due again only tonight');
		$rules->remove($id, 'occ');
		$this->assertTrue($r->due($this->outsideTheWindow(), '01:00-05:00'), 'removing a rule: what it covered must go soon');
	}

	public function testSwitchingTheModeAlsoTriggersAnEvaluation(): void {
		[$r, $db] = $this->reconciler();
		$this->finish($r);
		$settings = new \OCA\BuscadorDocumental\Scope\Settings($db);
		$settings->set('counsel_confirmed_at', '2026-11-01');
		$settings->set('dpia_ref', 'DPIA-1');
		$settings->setScopeMode('all_readable');
		$this->assertTrue($r->due($this->outsideTheWindow(), '01:00-05:00'));
	}

	public function testHousekeepingStillRunsWhileTheAdditionsCapSuspendsTheRun(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.settings (key, value) VALUES ('reconcile_cap', '1')");
		$db->execute("INSERT INTO aps_search.tombstone (file_id, reason, purge_after) VALUES (1, 'trashed', now() - interval '1 day')");
		$db->execute("INSERT INTO aps_search.audit_log (at, actor, action) VALUES (now() - interval '91 days', 'x', 'scope.add')");
		for ($i = 0; $i < 20; $i++) {
			if ($r->runOnce(30, '2026-10-17')['suspended'] ?? false) {
				break;
			}
		}
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.tombstone')[0]['n'], 'a long backfill cannot keep the ledger from being pruned');
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.audit_log WHERE at < now() - interval '90 days'")[0]['n']);
	}

	public function testPseudonymsOfUsersWhoNoLongerExistAreForgotten(): void {
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'alice');
		[$r, $db] = $this->reconciler(users: $users);
		foreach (['alice', 'ghost', 'system', 'occ'] as $uid) {
			(new Pseudonyms($db))->tokenFor($uid);
		}
		$this->finish($r);
		$left = array_column($db->rows('SELECT uid FROM aps_search.pseudonym ORDER BY uid'), 'uid');
		$this->assertSame(['alice'], $left, 'ghost is gone; system and occ were never people');
	}

	public function testOlderFileMetaRowsGetTheirStorageIdBackfilled(): void {
		[$r, $db] = $this->reconciler();
		$db->execute("INSERT INTO aps_search.file_meta (file_id, name, storage_id) VALUES (13, 'f', NULL), (15, 'f', NULL), (99, 'f', NULL)");
		$this->finish($r);
		$rows = $db->rows('SELECT file_id, storage_id FROM aps_search.file_meta ORDER BY file_id');
		$this->assertSame([[13, 1], [15, 1], [99, null]], array_map(static fn (array $x): array => [(int)$x['file_id'], $x['storage_id'] === null ? null : (int)$x['storage_id']], $rows), 'known files learn their storage; an unknown one stays NULL');
	}
}
