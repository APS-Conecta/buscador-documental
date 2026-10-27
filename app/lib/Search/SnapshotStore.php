<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Db\IndexDb;

/** The ranked candidate list a search pages through: ids only, tied to a hashed user, short-lived (S4 design section 3). */
class SnapshotStore {
	public const TTL_SECONDS = 600;
	/** A user keeps at most this many snapshots; the oldest go first. */
	public const PER_USER = 20;

	public function __construct(private IndexDb $db) {
	}

	/**
	 * @param list<int> $fileIds in display order @param list<int> $chunkIds the chunk shown for each file, same order
	 * @param bool $capped the list is incomplete: cursor pages do not rank again, so they report what page 1 found
	 */
	public function save(string $uid, array $fileIds, array $chunkIds, bool $capped): string {
		$qid = self::uuid();
		$this->db->execute("DELETE FROM aps_search.search_snapshot WHERE created_at < now() - interval '20 minutes'");
		$this->db->execute(
			'DELETE FROM aps_search.search_snapshot WHERE uid_hash = ? AND qid NOT IN (SELECT qid FROM aps_search.search_snapshot WHERE uid_hash = ? ORDER BY created_at DESC, qid LIMIT ?)',
			[Cursor::uidHash($uid), Cursor::uidHash($uid), self::PER_USER - 1],
		);
		$this->db->execute(
			'INSERT INTO aps_search.search_snapshot (qid, uid_hash, file_ids, chunk_ids, capped) VALUES (?::uuid, ?, ?::bigint[], ?::bigint[], ?::boolean)',
			[$qid, Cursor::uidHash($uid), IndexDb::textArray(array_map('strval', $fileIds)), IndexDb::textArray(array_map('strval', $chunkIds)), $capped ? 'true' : 'false'],
		);
		return $qid;
	}

	/** @return array{file_ids: list<int>, chunk_ids: list<int>, capped: bool}|null null when unknown, expired or somebody else's */
	public function load(string $qid, string $uid): ?array {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $qid) !== 1) {
			return null;
		}
		$rows = $this->db->rows(
			"SELECT to_json(file_ids)::text AS f, to_json(chunk_ids)::text AS c, capped::int AS k FROM aps_search.search_snapshot
			 WHERE qid = ?::uuid AND uid_hash = ? AND created_at > now() - make_interval(secs => ?)",
			[$qid, Cursor::uidHash($uid), self::TTL_SECONDS],
		);
		if ($rows === []) {
			return null;
		}
		return [
			'file_ids' => array_map('intval', json_decode((string)$rows[0]['f'], true) ?? []),
			'chunk_ids' => array_map('intval', json_decode((string)$rows[0]['c'], true) ?? []),
			'capped' => (int)$rows[0]['k'] === 1,
		];
	}

	private static function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
