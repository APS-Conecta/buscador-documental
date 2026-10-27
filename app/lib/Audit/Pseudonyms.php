<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Audit;

use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * uid -> random token. The table holds the uid only while the user exists; forgetting the user deletes the row, so
 * audit rows written with the token cannot be linked to a person any more (a keyed hash would need a key stored in
 * the same database). Every uid is mapped; actors that are not people are an Actor, not a uid, and are not mapped.
 */
class Pseudonyms {
	public function __construct(private IndexDb $db) {
	}

	/** What the audit and the scope rules store for whoever acted: an Actor's value, or a person's token. */
	public function actorToken(Actor|string $actor): string {
		return $actor instanceof Actor ? $actor->value : $this->tokenFor($actor);
	}

	public function tokenFor(string $uid): string {
		$rows = $this->db->rows('SELECT token FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
		if ($rows === []) {
			$this->db->execute('INSERT INTO aps_search.pseudonym (uid, token) VALUES (?, ?) ON CONFLICT (uid) DO NOTHING', [$uid, bin2hex(random_bytes(16))]);
			$rows = $this->db->rows('SELECT token FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
		}
		return (string)$rows[0]['token'];
	}

	/** The token of a user who already has one; never creates it. */
	public function existing(string $uid): ?string {
		$rows = $this->db->rows('SELECT token FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
		return $rows === [] ? null : (string)$rows[0]['token'];
	}

	public function forget(string $uid): void {
		$this->db->execute('DELETE FROM aps_search.pseudonym WHERE uid = ?', [$uid]);
	}
}
