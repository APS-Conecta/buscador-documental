<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Reconcile\GroupfolderFingerprint;

/** Groupfolder grant and ACL changes fire no typed event: a fingerprint poll finds them (ADR-0013). */
class GroupfolderPollTest extends DbTestCase {
	private function fixture(): void {
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('DROP TABLE IF EXISTS oc_group_folders, oc_group_folders_groups, oc_group_folders_acl');
		$admin->exec('CREATE TABLE oc_group_folders (folder_id bigint primary key, storage_id bigint, acl int default 0)');
		// groupfolders keeps a Team grant as group_id '' plus the Team id in circle_id, and a group grant with circle_id ''
		$admin->exec("CREATE TABLE oc_group_folders_groups (folder_id bigint, group_id text, permissions int, circle_id text DEFAULT '')");
		$admin->exec('CREATE TABLE oc_group_folders_acl (id serial primary key, fileid bigint, mapping_type text, mapping_id text, mask bigint, permissions bigint)');
		$admin->exec("INSERT INTO oc_group_folders VALUES (1, 100, 0), (2, 200, 0)");
		$admin->exec("INSERT INTO oc_group_folders_groups VALUES (1, 'med', 31), (2, 'enf', 31)");
		$admin->exec('GRANT SELECT ON oc_group_folders, oc_group_folders_groups, oc_group_folders_acl TO nextcloud');
		foreach ([[1, 100], [2, 101], [3, 200]] as [$id, $storage]) {
			$this->app()->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, 'e', 'indexed')");
			$this->app()->exec("INSERT INTO aps_search.file_meta (file_id, name, storage_id) VALUES ($id, 'f', $storage)");
		}
		// files 1 and 2 live on storage 100 (folder 1); file 3 on storage 200 (folder 2); 2 is on 101: not a folder storage
	}

	private function poll(): array {
		$db = new IndexDb($this->connection($this->app()));
		return [new GroupfolderFingerprint($db), $db];
	}

	private function dirty(IndexDb $db): array {
		return array_map('intval', array_column($db->rows('SELECT file_id FROM aps_search.file_state WHERE acl_dirty ORDER BY 1'), 'file_id'));
	}

	public function testTheFirstPollOnlyRecordsABaseline(): void {
		$this->fixture();
		[$g, $db] = $this->poll();
		$this->assertSame(0, $g->check());
		$this->assertSame([], $this->dirty($db));
		$this->assertSame(0, $g->check(), 'nothing changed');
	}

	public function testAGrantChangeMarksOnlyThatFoldersFilesDirty(): void {
		$this->fixture();
		[$g, $db] = $this->poll();
		$g->check();
		self::connect('postgres', 'dev', self::$dbName)->exec("INSERT INTO oc_group_folders_groups VALUES (1, 'admin', 31)");
		$this->assertSame(1, $g->check(), 'one folder changed');
		$this->assertSame([1], $this->dirty($db), 'file 1 is on folder 1; file 3 is on folder 2; file 2 is on no folder storage');
		$this->assertSame(0, $g->check(), 'the new state is the baseline');
	}

	public function testAddingATeamGrantMarksTheFolderDirty(): void {
		$this->fixture();
		[$g, $db] = $this->poll();
		$g->check();
		self::connect('postgres', 'dev', self::$dbName)->exec("INSERT INTO oc_group_folders_groups VALUES (1, '', 31, 'teamA')");
		$this->assertSame(1, $g->check());
		$this->assertSame([1], $this->dirty($db));
	}

	public function testSwappingOneTeamForAnotherMarksTheFolderDirty(): void {
		$this->fixture();
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec("INSERT INTO oc_group_folders_groups VALUES (1, '', 31, 'teamA')");
		[$g, $db] = $this->poll();
		$g->check();
		$admin->exec("DELETE FROM oc_group_folders_groups WHERE circle_id = 'teamA'");
		$admin->exec("INSERT INTO oc_group_folders_groups VALUES (1, '', 31, 'teamB')");
		$this->assertSame(1, $g->check(), 'same permissions, another Team');
		$this->assertSame([1], $this->dirty($db));
	}

	public function testTwoUnchangedTeamGrantsMarkNothing(): void {
		$this->fixture();
		self::connect('postgres', 'dev', self::$dbName)->exec("INSERT INTO oc_group_folders_groups VALUES (1, '', 31, 'teamA'), (1, '', 31, 'teamB')");
		[$g, $db] = $this->poll();
		$g->check();
		$this->assertSame(0, $g->check(), 'two Team grants share the empty group id: the order stays stable');
		$this->assertSame(0, $g->check());
		$this->assertSame([], $this->dirty($db));
	}

	public function testABaselineFromTheOldFormulaMovesOnce(): void {
		$this->fixture();
		// what the poll stored before the Team id was part of the grants (group_id:permissions only)
		$this->set('gf_fp:1', md5('100|0|' . md5('med:31')));
		$this->set('gf_fp:2', md5('200|0|' . md5('enf:31')));
		[$g, $db] = $this->poll();
		$this->assertSame(2, $g->check(), 'every folder refreshes once, so principals stored as {g:} are rewritten');
		$this->assertSame([1, 3], $this->dirty($db));
		$this->assertSame(0, $g->check());
	}

	public function testEnablingAdvancedAclOrChangingRulesMarksEverything(): void {
		$this->fixture();
		[$g, $db] = $this->poll();
		$g->check();
		self::connect('postgres', 'dev', self::$dbName)->exec("INSERT INTO oc_group_folders_acl (fileid, mapping_type, mapping_id, mask, permissions) VALUES (5, 'group', 'med', 31, 0)");
		$this->assertSame(2, $g->check(), 'a rule can sit anywhere: every team folder is looked at');
		$this->assertSame([1, 3], $this->dirty($db));
	}

	public function testTheAdvancedAclFlagOfOneFolderIsPickedUp(): void {
		$this->fixture();
		[$g, $db] = $this->poll();
		$g->check();
		self::connect('postgres', 'dev', self::$dbName)->exec('UPDATE oc_group_folders SET acl = 1 WHERE folder_id = 2');
		$this->assertSame(1, $g->check());
		$this->assertSame([3], $this->dirty($db));
	}

	public function testWithoutTheGroupfoldersAppItIsAQuietNoOp(): void {
		$db = new IndexDb($this->connection($this->app()));
		self::connect('postgres', 'dev', self::$dbName)->exec('DROP TABLE IF EXISTS oc_group_folders, oc_group_folders_groups, oc_group_folders_acl');
		$this->assertSame(0, (new GroupfolderFingerprint($db))->check());
	}

	public function testRemovedFoldersForgetTheirFingerprint(): void {
		$this->fixture();
		[$g, $db] = $this->poll();
		$g->check();
		self::connect('postgres', 'dev', self::$dbName)->exec('DELETE FROM oc_group_folders WHERE folder_id = 2');
		$g->check();
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.settings WHERE key = 'gf_fp:2'")[0]['n']);
	}
}
