<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Audit;

use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * uid -> random token. The table holds the uid only while the user exists; forgetting the user deletes the row, so
 * audit rows written with the token cannot be linked to a person any more (a keyed hash would need a key stored in
 * the same database). The `system` actor is not a person and is not mapped.
 */
class Pseudonyms {
	public const SYSTEM = 'system';
	/** Actors that are not people: background jobs and the command line. */
	public const NON_PEOPLE = ['system', 'occ'];

	public function __construct(private IndexDb $db) {
	}

	public function tokenFor(string $uid): string {
		if (in_array($uid, self::NON_PEOPLE, true)) {
			return $uid;
		}
		$rows = $this->db->rows('SELECT token FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
		if ($rows === []) {
			$this->db->execute('INSERT INTO aps_search.pseudonym (uid, token) VALUES (?, ?) ON CONFLICT (uid) DO NOTHING', [$uid, bin2hex(random_bytes(16))]);
			$rows = $this->db->rows('SELECT token FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
		}
		return (string)$rows[0]['token'];
	}

	public function forget(string $uid): void {
		$this->db->execute('DELETE FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
	}
}
