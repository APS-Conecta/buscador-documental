<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use InvalidArgumentException;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\FileTree;
use OCA\BuscadorDocumental\Scope\ScopeLocked;
use OCA\BuscadorDocumental\Scope\ScopePredicate;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\ScopeSet;
use OCA\BuscadorDocumental\Scope\ScopeSql;
use OCA\BuscadorDocumental\Scope\Settings;

/** S3 phase 2 against real PostgreSQL: settings, rules, pseudonyms, audit, and the bulk scope query. */
class ScopeDbTest extends DbTestCase {
	private function db(): IndexDb {
		return new IndexDb($this->connection($this->app()));
	}

	private function audit(IndexDb $db): AuditLog {
		return new AuditLog($db, new Pseudonyms($db));
	}

	private function rules(IndexDb $db): ScopeRules {
		$pseudonyms = new Pseudonyms($db);
		return new ScopeRules($db, new AuditLog($db, $pseudonyms), $pseudonyms);
	}

	// ---- settings ----
	public function testDefaultsAreOptInAndNotPaused(): void {
		$s = new Settings($this->db());
		$this->assertSame('opt_in', $s->scopeMode());
		$this->assertFalse($s->isPaused());
		$this->assertNull($s->get('notice_ack_at'));
	}

	public function testAllReadableIsRefusedUntilCounselAndDpiaAreRecorded(): void {
		$s = new Settings($this->db());
		try {
			$s->setScopeMode('all_readable');
			$this->fail('expected refusal');
		} catch (ScopeLocked $e) {
			$this->assertStringContainsString('counsel', $e->getMessage());
		}
		$s->set('counsel_confirmed_at', '2026-11-01');
		try {
			$s->setScopeMode('all_readable');
			$this->fail('expected refusal without the DPIA');
		} catch (ScopeLocked) {
			$this->addToAssertionCount(1);
		}
		$s->set('dpia_ref', 'DPIA-2026-1');
		$s->setScopeMode('all_readable');
		$this->assertSame('all_readable', $s->scopeMode());
	}

	public function testAStoredAllReadableWithoutTheGateIsIgnored(): void {
		$db = $this->db();
		$db->execute("INSERT INTO aps_search.settings (key, value) VALUES ('scope_mode', 'all_readable')");      // written behind our back
		$this->assertSame('opt_in', (new Settings($db))->scopeMode(), 'the effective mode falls back to the safe one');
	}

	public function testUnknownModesAndPauseRoundTrip(): void {
		$s = new Settings($this->db());
		$this->expectException(InvalidArgumentException::class);
		try {
			$s->setScopeMode('everything');
		} finally {
			$s->setPaused(true);
			$this->assertTrue($s->isPaused());
			$s->setPaused(false);
			$this->assertFalse($s->isPaused());
		}
	}

	// ---- rules ----
	public function testRulesAreDeduplicatedListedAndRemovedAndAuditedWithoutNames(): void {
		$db = $this->db();
		$rules = $this->rules($db);
		$a = $rules->addFolder(11, 'admin');
		$this->assertSame($a, $rules->addFolder(11, 'admin'), 'adding the same folder twice is one rule');
		$rules->addGroupfolders('admin');
		$set = $rules->current();
		$this->assertSame([11], $set->folderIds);
		$this->assertTrue($set->allGroupfolders);
		$rules->remove($a, 'admin');
		$this->assertSame([], $rules->current()->folderIds);
		$log = $db->rows('SELECT action, actor, subject_token, detail FROM aps_search.audit_log ORDER BY id');
		$this->assertSame(['scope.add', 'scope.add', 'scope.remove'], array_column($log, 'action'));
		$this->assertStringNotContainsString('admin', json_encode($log), 'actors are pseudonyms');
	}

	public function testTheRuleAndItsAuditRowResolveTheActorTheSameWay(): void {
		$db = $this->db();
		$id = $this->rules($db)->addFolder(11, 'occ');
		$token = $db->rows('SELECT created_by_token FROM aps_search.scope_rule WHERE id = ?', [$id])[0]['created_by_token'];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token, 'a user named occ is named by a random token');
		$this->assertSame([$token], array_column($db->rows("SELECT actor FROM aps_search.audit_log WHERE action = 'scope.add'"), 'actor'));
	}

	public function testTheFingerprintChangesWithRulesAndModeOnly(): void {
		$db = $this->db();
		$rules = $this->rules($db);
		$f0 = $rules->fingerprint();
		$id = $rules->addFolder(5, 'admin');
		$f1 = $rules->fingerprint();
		$this->assertNotSame($f0, $f1);
		$this->assertSame($f1, $rules->fingerprint());
		$rules->remove($id, 'admin');
		$this->assertSame($f0, $rules->fingerprint());
	}

	// ---- pseudonyms and audit ----
	public function testPseudonymsAreStableRandomAndForgettable(): void {
		$db = $this->db();
		$p = new Pseudonyms($db);
		$t = $p->tokenFor('alice');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $t);
		$this->assertSame($t, $p->tokenFor('alice'));
		$this->assertNotSame($t, $p->tokenFor('bob'));
		foreach (['system', 'occ'] as $uid) {
			$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $p->tokenFor($uid), "a user named $uid is a person like any other");
			$this->assertSame($p->tokenFor($uid), $p->tokenFor($uid));
		}
		$this->audit($db)->record('alice', 'scope.add', 'bob', ['rule_id' => 1]);
		$p->forget('bob');
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.pseudonym WHERE uid = 'bob'")[0]['n']);
		$this->assertNotSame((new Pseudonyms($db))->tokenFor('bob'), $db->rows('SELECT subject_token FROM aps_search.audit_log')[0]['subject_token'], 'a returning uid is not linked to the old rows');
		$this->assertStringNotContainsString('bob', json_encode($db->rows('SELECT * FROM aps_search.audit_log')));
	}

	public function testAuditRefusesTextAndKnownActionsOnly(): void {
		$a = $this->audit($this->db());
		$this->expectException(InvalidArgumentException::class);
		$a->record('alice', 'scope.add', null, ['reason' => 'contrato de Juan Pérez.pdf']);
	}

	public function testAuditRefusesAnUnknownAction(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->audit($this->db())->record('alice', 'something.else', null, []);
	}

	public function testAuditRetentionPrunesOnlyOldRows(): void {
		$db = $this->db();
		$a = $this->audit($db);
		$a->record('alice', 'scope.add', null, []);
		$db->execute("INSERT INTO aps_search.audit_log (at, actor, action) VALUES (now() - interval '91 days', 'x', 'scope.add')");
		$db->execute("INSERT INTO aps_search.audit_log (at, actor, action) VALUES (now() - interval '89 days', 'x', 'scope.add')");
		$this->assertSame(1, $a->prune(90));
		$this->assertSame(2, (int)$db->rows('SELECT count(*) AS n FROM aps_search.audit_log')[0]['n']);
	}

	// ---- the bulk form equals the per-file form ----
	private function fixture(): void {
		$pdo = $this->app();
		$pdo->exec('SET ROLE bd_owner');
		$pdo->exec('RESET ROLE');
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('DROP TABLE IF EXISTS oc_filecache, oc_storages, oc_group_folders, oc_mimetypes');
		$admin->exec("CREATE TABLE oc_mimetypes (id bigint primary key, mimetype text)");
		$admin->exec("INSERT INTO oc_mimetypes VALUES (1, 'httpd/unix-directory'), (2, 'application/pdf')");
		$admin->exec('CREATE TABLE oc_storages (numeric_id bigint primary key, id text)');
		$admin->exec('CREATE TABLE oc_group_folders (folder_id bigint primary key, storage_id bigint)');
		$admin->exec('CREATE TABLE oc_filecache (fileid bigint primary key, storage bigint, path text, parent bigint, mimetype bigint default 1, size bigint default 10)');
		$admin->exec('CREATE INDEX ON oc_filecache (parent)');
		$admin->exec("INSERT INTO oc_storages VALUES (1, 'home::alice'), (2, 'local::/var/www/html/data/__groupfolders/1/'), (3, 'local::/mnt/ext/')");
		$admin->exec('INSERT INTO oc_group_folders VALUES (1, 2)');
		$admin->exec("INSERT INTO oc_filecache (fileid, storage, path, parent, mimetype) VALUES
			(10, 1, 'files', -1, 1), (11, 1, 'files/top', 10, 1), (12, 1, 'files/top/mid', 11, 1), (13, 1, 'files/top/mid/a.pdf', 12, 2),
			(14, 1, 'files/other', 10, 1), (15, 1, 'files/other/b.pdf', 14, 2), (16, 1, 'trash/old.pdf', -1, 2), (17, 1, 'files_trashbin/x', -1, 2),
			(20, 2, 'files', -1, 1), (21, 2, 'files/team.pdf', 20, 2), (22, 2, 'versions/v1', -1, 2), (23, 2, 'trash/t.pdf', -1, 2),
			(30, 3, 'files/ext.pdf', -1, 2)");
		$admin->exec('GRANT SELECT ON oc_filecache, oc_storages, oc_group_folders, oc_mimetypes TO nextcloud');
	}

	/** @dataProvider sets */
	public function testTheBulkQueryEqualsThePerFilePredicate(ScopeSet $set): void {
		$this->fixture();
		$conn = $this->connection($this->app());
		$tree = new FileTree($conn);
		$predicate = new ScopePredicate($tree);
		$perFile = [];
		foreach ([13, 15, 16, 17, 21, 22, 23, 30] as $id) {       // files only: folders are never indexed
			if ($predicate->inScope($set, $id)) {
				$perFile[] = $id;
			}
		}
		[$sql, $params] = ScopeSql::eligible($set);
		$bulk = array_map('intval', array_column((new IndexDb($conn))->rows($sql, $params), 'file_id'));
		sort($bulk);
		$this->assertSame($perFile, $bulk);
	}

	public static function sets(): array {
		return [
			'no rules' => [new ScopeSet('opt_in', [], false)],
			'one folder' => [new ScopeSet('opt_in', [11], false)],
			'two folders' => [new ScopeSet('opt_in', [12, 14], false)],
			'groupfolders' => [new ScopeSet('opt_in', [], true)],
			'both' => [new ScopeSet('opt_in', [11], true)],
			'all readable' => [new ScopeSet('all_readable', [], false)],
		];
	}

	public function testThePreviewCountsFilesAndBytesOnly(): void {
		$this->fixture();
		$conn = $this->connection($this->app());
		$set = new ScopeSet('opt_in', [11], true);
		$p = ScopeSql::preview(new IndexDb($conn), $set);
		$this->assertSame(['files' => 2, 'bytes' => 20], $p);                 // 13 and 21; folders are not counted
	}

	public function testTheFileTreeReadsStorageKindsFromTheRealTables(): void {
		$this->fixture();
		$t = new FileTree($this->connection($this->app()));
		$this->assertSame('home', $t->storageKind(1));
		$this->assertSame('groupfolder', $t->storageKind(2));
		$this->assertSame('other', $t->storageKind(3));
		$this->assertNull($t->node(999));
		$this->assertSame(['storage' => 1, 'path' => 'files/top', 'parent' => 10], $t->node(11));
	}

	/** @dataProvider lookalikes */
	public function testOnlyLocalStoragesInsideTheDataDirectoryCountAsTeamFolders(string $storageId, string $expected): void {
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('DROP TABLE IF EXISTS oc_storages');
		$admin->exec('CREATE TABLE oc_storages (numeric_id bigint primary key, id text)');
		$admin->prepare('INSERT INTO oc_storages VALUES (50, ?)')->execute([$storageId]);
		$admin->exec('GRANT SELECT ON oc_storages TO nextcloud');
		$this->assertSame($expected, (new FileTree($this->connection($this->app())))->storageKind(50));
	}

	public static function lookalikes(): array {
		return [
			'a real team folder' => ['local::/var/www/html/data/__groupfolders/3/', 'groupfolder'],
			'without the slash' => ['local::/var/www/html/data/__groupfolders/3', 'groupfolder'],
			'an external mount that merely ends alike' => ['smb::fileserver//share/__groupfolders/3/', 'other'],
			'no local prefix' => ['__groupfolders/3/', 'other'],
			'another object store' => ['object::store:bucket__groupfolders/3', 'other'],
		];
	}
}
