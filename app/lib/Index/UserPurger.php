<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

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
		$rows = $this->db->rows('SELECT storage_numeric_id FROM aps_search.purge_pending WHERE token = ?', [$token]);
		$erased = $rows === [] ? 0 : $this->purgeStorage((int)$rows[0]['storage_numeric_id']);
		$this->audit->record('system', 'purge_user', $uid, ['count' => $erased, 'reason' => 'user_deleted']);
		$this->db->execute('DELETE FROM aps_search.purge_pending WHERE token = ?', [$token]);
		$this->pseudonyms->forget($uid);              // from here on the audit rows are anonymous
		return $erased;
	}

	/** `occ buscador:purge-user` for a user who still exists. @return int files erased */
	public function purgeUser(string $uid): int {
		$storage = $this->homeStorage($uid);
		if ($storage === null) {
			return 0;
		}
		$erased = $this->purgeStorage($storage);
		$this->audit->record('occ', 'purge_user', $uid, ['count' => $erased, 'reason' => 'admin_purge']);
		return $erased;
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
