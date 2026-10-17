<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\UserPurger;

/** A-SEC-6: erasure on user deletion, with an audit trail that cannot be linked back to the person. */
class UserPurgeTest extends DbTestCase {
	private function purger(): array {
		$conn = $this->connection($this->app());
		$db = new IndexDb($conn);
		$p = new Pseudonyms($db);
		// the storage of a user is looked up in Nextcloud's tables: a tiny fixture of them
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('DROP TABLE IF EXISTS oc_storages');
		$admin->exec('CREATE TABLE oc_storages (numeric_id bigint primary key, id text)');
		$admin->exec("INSERT INTO oc_storages VALUES (7, 'home::alice'), (8, 'home::bob')");
		$admin->exec('GRANT SELECT ON oc_storages TO nextcloud');
		return [new UserPurger($db, $conn, new AuditLog($db, $p), $p), $db, $p];
	}

	private function indexed(int $id, int $storage): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, 'e', 'indexed')");
		$a->exec("INSERT INTO aps_search.page (file_id, page_no, text) VALUES ($id, 1, 'x')");
		$a->exec("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES ($id, 1, 0, 'x')");
		$a->exec("INSERT INTO aps_search.file_acl (file_id, principals) VALUES ($id, ARRAY['u:x'])");
		$a->exec("INSERT INTO aps_search.file_meta (file_id, name, storage_id) VALUES ($id, 'f', $storage)");
	}

	public function testPurgeStorageErasesThatStoragesFilesOnlyAndForgetsTheirState(): void {
		[$purger] = $this->purger();
		foreach ([1, 2, 3] as $id) {
			$this->indexed($id, 7);
		}
		$this->indexed(9, 8);
		$this->assertSame(3, $purger->purgeStorage(7));
		foreach (['file_state', 'page', 'chunk', 'file_acl', 'file_meta'] as $t) {
			foreach ([1, 2, 3] as $id) {
				$this->assertSame(0, $this->rowCount($t, $id), "$t $id");
			}
			$this->assertSame(1, $this->rowCount($t, 9), "$t: another user's file is untouched");
		}
	}

	public function testALargeStorageIsPurgedInBatches(): void {
		[$purger, $db] = $this->purger();
		$db->execute("INSERT INTO aps_search.file_meta (file_id, name, storage_id) SELECT g, 'f', 7 FROM generate_series(1, 2500) g");
		$this->assertSame(2500, $purger->purgeStorage(7));
		$this->assertSame(0, $this->rowCount('file_meta'));
	}

	public function testTheDeletionFlowErasesAuditsAndMakesTheAuditUnlinkable(): void {
		[$purger, $db, $p] = $this->purger();
		$this->indexed(1, 7);
		$this->indexed(2, 8);
		$purger->recordPending('alice');
		$this->assertSame(1, (int)$db->rows('SELECT count(*) AS n FROM aps_search.purge_pending')[0]['n']);
		$this->assertSame(1, $purger->purgePending('alice'));
		$this->assertSame(0, $this->rowCount('chunk', 1));
		$this->assertSame(1, $this->rowCount('chunk', 2), 'bob is untouched');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.purge_pending')[0]['n'], 'the bookkeeping row is gone');
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.pseudonym WHERE uid = 'alice'")[0]['n'], 'the mapping is gone: the audit is unlinkable');
		$audit = $db->rows("SELECT action, detail FROM aps_search.audit_log WHERE action = 'purge_user'");
		$this->assertCount(1, $audit);
		$this->assertSame(['count' => 1, 'reason' => 'user_deleted'], json_decode($audit[0]['detail'], true));
		$this->assertStringNotContainsString('alice', json_encode($db->rows('SELECT * FROM aps_search.audit_log')));
		$this->assertNotSame($p->tokenFor('alice'), $db->rows('SELECT subject_token FROM aps_search.audit_log')[0]['subject_token'], 'a user who returns under the same name is a stranger to the old audit rows');
	}

	public function testPurgingTwiceOrWithNothingPendingIsHarmless(): void {
		[$purger] = $this->purger();
		$this->assertSame(0, $purger->purgePending('nobody'));
		$purger->recordPending('alice');
		$purger->recordPending('alice');
		$this->assertSame(0, $purger->purgePending('alice'));
		$this->assertSame(0, $purger->purgePending('alice'));
	}

	public function testPurgeUserByNameForAUserWhoStillExists(): void {
		[$purger] = $this->purger();
		$this->indexed(1, 7);
		$this->assertSame(1, $purger->purgeUser('alice'));
		$this->assertSame(0, $this->rowCount('chunk', 1));
		$this->assertSame(0, $purger->purgeUser('unknown_user'));
	}

	public function testPurgeUserByStorageIdForAUserAlreadyGone(): void {
		[$purger] = $this->purger();
		$this->indexed(1, 77);
		$this->assertSame(1, $purger->purgeStorage(77));
	}
}
