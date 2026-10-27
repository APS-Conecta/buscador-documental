<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCP\IDBConnection;

/**
 * Erasure on user deletion (A-SEC-6). Files are found by the storage they live on (recorded in file_meta), not through
 * Nextcloud's file cache, which core clears on the same event in an unspecified order. The audit trail names the user
 * only by a random token, and the token's mapping is deleted with the user, so the trail cannot be linked back.
 */
class UserPurger {
	private const BATCH = 1000;

	public function __construct(private IndexDb $db, private IDBConnection $nc, private AuditLog $audit, private Pseudonyms $pseudonyms) {
	}

	/** Before deletion: remember which storage was theirs. The deletion can still be vetoed, so nothing is erased yet. */
	public function recordPending(string $uid): void {
		$storage = $this->homeStorage($uid);
		if ($storage === null) {
			return;
		}
		$this->learnStorage($storage);                // core clears the file cache on the deletion event: this is the last chance
		$this->db->execute(
			'INSERT INTO aps_search.purge_pending (token, storage_numeric_id) VALUES (?, ?)
			 ON CONFLICT (token) DO UPDATE SET storage_numeric_id = EXCLUDED.storage_numeric_id',
			[$this->pseudonyms->tokenFor($uid), $storage],
		);
	}

	/** After deletion. @return int files erased */
	public function purgePending(string $uid): int {
		$token = $this->pseudonyms->existing($uid);
		if ($token === null) {
			return 0;
		}
		try {
			$rows = $this->db->rows('SELECT storage_numeric_id FROM aps_search.purge_pending WHERE token = ?', [$token]);
			$erased = $rows === [] ? 0 : $this->purgeStorage((int)$rows[0]['storage_numeric_id']);
			$this->audit->record(Actor::System, 'purge_user', $uid, ['count' => $erased, 'reason' => 'user_deleted']);
			return $erased;
		} finally {
			// also when the erasure failed: the uid must not stay linkable to the audit rows, and what was missed is
			// removed by the nightly reconciliation (its files are gone from Nextcloud)
			$this->db->execute('DELETE FROM aps_search.purge_pending WHERE token = ?', [$token]);
			$this->pseudonyms->forget($uid);
		}
	}

	/** `occ buscador:purge-user` for a user who still exists. @return int files erased */
	public function purgeUser(string $uid): int {
		$storage = $this->homeStorage($uid);
		if ($storage === null) {
			return 0;
		}
		$this->learnStorage($storage);
		$erased = $this->purgeStorage($storage);
		$this->audit->record(Actor::Occ, 'purge_user', $uid, ['count' => $erased, 'reason' => 'admin_purge']);
		return $erased;
	}

	/** `occ buscador:purge-user --storage-id` (a user already deleted): audited like the other way. */
	public function purgeStorageAudited(int $storageId): int {
		$erased = $this->purgeStorage($storageId);
		$this->audit->record(Actor::Occ, 'purge_user', null, ['count' => $erased, 'reason' => 'admin_purge', 'storage_id' => $storageId]);
		return $erased;
	}

	/** Rows indexed before S3 carry no storage id: learn it from the file cache while the cache still has the user's files. */
	private function learnStorage(int $storage): void {
		$this->db->execute(
			'UPDATE aps_search.file_meta SET storage_id = ? WHERE storage_id IS NULL AND file_id IN (SELECT fileid FROM *PREFIX*filecache WHERE storage = ?)',
			[$storage, $storage],
		);
	}

	/** Erases every indexed file of a storage and forgets their state. @return int files erased */
	public function purgeStorage(int $storageId): int {
		$total = 0;
		while (true) {
			$ids = array_map(static fn (array $r): int => (int)$r['file_id'],
				$this->db->rows('SELECT file_id FROM aps_search.file_meta WHERE storage_id = ? ORDER BY file_id LIMIT ?', [$storageId, self::BATCH]));
			if ($ids === []) {
				return $total;
			}
			$this->db->rows('SELECT aps_search.purge_files(?::bigint[])', ['{' . implode(',', $ids) . '}']);
			$total += count($ids);
		}
	}

	private function homeStorage(string $uid): ?int {
		$v = $this->nc->executeQuery('SELECT numeric_id FROM *PREFIX*storages WHERE id = ?', ['home::' . $uid])->fetchOne();
		return $v === false ? null : (int)$v;
	}
}
