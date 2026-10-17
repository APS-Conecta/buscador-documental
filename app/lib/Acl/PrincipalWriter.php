<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * Writes file_acl.principals for one file, synchronously (S1; the outbox and acl_dirty processing are S3).
 * Models owner, direct user shares, group shares and groupfolder group grants. Anything else (advanced ACL deny,
 * circles, Talk/Deck) is not modelled: such a file also carries `x:recheck`, which every caller's set contains, so the
 * prefilter never drops it and the live recheck decides.
 */
class PrincipalWriter {
	public function __construct(private IndexDb $db, private FileFactsSource $facts) {
	}

	/** @return list<string> sorted, unique */
	public static function principalsFor(FileFacts $f): array {
		$p = [];
		if ($f->ownerUid !== null && $f->ownerUid !== '') {
			$p[] = 'u:' . $f->ownerUid;
		}
		foreach ($f->shareUserIds as $uid) {
			$p[] = 'u:' . $uid;
		}
		foreach (array_merge($f->shareGroupIds, $f->groupfolderGroupIds) as $gid) {
			$p[] = 'g:' . $gid;
		}
		if ($f->unmodelledAccess) {
			$p[] = PrincipalResolver::RECHECK; // stored, so the caller-side marker matches and the live recheck decides
		}
		$p = array_values(array_unique($p));
		sort($p);
		return $p;
	}

	public function write(int $fileId): bool {
		$facts = $this->facts->load($fileId);
		if ($facts === null) {
			return false;
		}
		$this->writeFacts($facts);
		return true;
	}

	public function writeFacts(FileFacts $facts): void {
		$this->db->execute(
			'INSERT INTO aps_search.file_acl (file_id, principals, acl_ver) VALUES (?, ?::text[], 1)
			 ON CONFLICT (file_id) DO UPDATE SET principals = EXCLUDED.principals, acl_ver = aps_search.file_acl.acl_ver + 1
			 WHERE aps_search.file_acl.principals IS DISTINCT FROM EXCLUDED.principals',
			[$facts->fileId, IndexDb::textArray(self::principalsFor($facts))],
		);
	}
}
