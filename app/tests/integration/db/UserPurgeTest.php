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
		$admin->exec("INSERT INTO oc_storages VALUES (7, 'home::alice'), (8, 'home::bob'), (9, 'home::occ'), (10, 'home::system')");
		$admin->exec('DROP TABLE IF EXISTS oc_filecache');
		$admin->exec('CREATE TABLE oc_filecache (fileid bigint primary key, storage bigint)');
		$admin->exec('GRANT SELECT ON oc_storages, oc_filecache TO nextcloud');
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

	/** @dataProvider deletedUsers */
	public function testTheDeletionFlowErasesAuditsAndMakesTheAuditUnlinkable(string $uid, int $storage): void {
		[$purger, $db, $p] = $this->purger();
		$this->indexed(1, $storage);
		$this->indexed(2, 8);
		$purger->recordPending($uid);
		$this->assertSame(1, (int)$db->rows('SELECT count(*) AS n FROM aps_search.purge_pending')[0]['n']);
		$this->assertSame(1, $purger->purgePending($uid));
		$this->assertSame(0, $this->rowCount('chunk', 1));
		$this->assertSame(1, $this->rowCount('chunk', 2), 'bob is untouched');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.purge_pending')[0]['n'], 'the bookkeeping row is gone');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.pseudonym WHERE uid = ?', [$uid])[0]['n'], 'the mapping is gone: the audit is unlinkable');
		$audit = $db->rows("SELECT actor, subject_token, detail FROM aps_search.audit_log WHERE action = 'purge_user'");
		$this->assertCount(1, $audit);
		$this->assertSame(['count' => 1, 'reason' => 'user_deleted'], json_decode($audit[0]['detail'], true));
		$this->assertSame('system', $audit[0]['actor'], 'the purge after a deletion is done by the system');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $audit[0]['subject_token'], 'the user is named only by a random token');
		$this->assertNotSame($p->tokenFor($uid), $audit[0]['subject_token'], 'a user who returns under the same name is a stranger to the old audit rows');
	}

	/** A uid that is also the name of a non-person actor is erased like any other. */
	public static function deletedUsers(): array {
		return [
			'alice' => ['alice', 7],
			'occ' => ['occ', 9],
			'system' => ['system', 10],
		];
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

	public function testRecordingThePendingDeletionBackfillsOlderRowsSoTheyAreFound(): void {
		[$purger, $db] = $this->purger();
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('INSERT INTO oc_filecache VALUES (1, 7), (2, 7), (3, 8)');
		foreach ([1, 2, 3] as $id) {
			$this->indexed($id, 0);
			$db->execute('UPDATE aps_search.file_meta SET storage_id = NULL WHERE file_id = ?', [$id]);            // indexed before S3
		}
		$purger->recordPending('alice');                                  // core has not cleared the file cache yet
		$this->assertSame(2, $purger->purgePending('alice'), 'the two files of alice, found although they carried no storage id');
		$this->assertSame(1, $this->rowCount('chunk', 3), "bob's file is untouched");
	}

	public function testTheUidMappingIsForgottenEvenWhenThePurgeFails(): void {
		[, $db, $p] = $this->purger();
		$conn = $this->connection($this->app());
		$failing = new class($db, $conn, new AuditLog($db, $p), $p) extends UserPurger {
			public function purgeStorage(int $storageId): int {
				throw new \RuntimeException('database hiccup');
			}
		};
		$failing->recordPending('alice');
		try {
			$failing->purgePending('alice');
			$this->fail('the failure still reaches the caller');
		} catch (\RuntimeException) {
			$this->addToAssertionCount(1);
		}
		$this->assertSame(0, (int)$db->rows("SELECT count(*) AS n FROM aps_search.pseudonym WHERE uid = 'alice'")[0]['n'], 'the uid must not stay linkable to the audit rows');
	}

	public function testPurgingByStorageIdIsAuditedToo(): void {
		[$purger, $db] = $this->purger();
		$this->indexed(1, 77);
		$this->assertSame(1, $purger->purgeStorageAudited(77));
		$row = $db->rows("SELECT detail FROM aps_search.audit_log WHERE action = 'purge_user'")[0];
		$this->assertSame(['count' => 1, 'reason' => 'admin_purge', 'storage_id' => 77], json_decode($row['detail'], true));
	}
}
