<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * The bulk form of the scope predicate, for reconciliation and previews: set-based, one recursive query over the
 * parent index, never a path-prefix scan (PostgreSQL has no (storage, path) index) and never one query per file.
 * It must return exactly the files ScopePredicate accepts (tested against the same fixture).
 */
final class ScopeSql {
	private const STORAGE_OK = "(s.id LIKE 'home::%' OR s.id ~ '^local::/.+/__groupfolders/[0-9]+/?$')";

	/** @return array{0: string, 1: list<string>} SQL returning one column, file_id, of indexable non-directory files */
	public static function eligible(ScopeSet $set): array {
		$params = [];
		$parts = [];
		if ($set->mode === 'all_readable') {
			$parts[] = 'SELECT c.fileid FROM *PREFIX*filecache c JOIN *PREFIX*storages s ON s.numeric_id = c.storage WHERE ' . self::STORAGE_OK;
		} else {
			if ($set->folderIds !== []) {
				$parts[] = 'SELECT fileid FROM sub';
				$params[] = '{' . implode(',', array_map('intval', $set->folderIds)) . '}';
			}
			if ($set->allGroupfolders) {
				$parts[] = "SELECT c.fileid FROM *PREFIX*filecache c JOIN *PREFIX*storages s ON s.numeric_id = c.storage WHERE s.id ~ '^local::/.+/__groupfolders/[0-9]+/?\$'";
			}
		}
		if ($parts === []) {
			return ['SELECT NULL::bigint AS file_id WHERE false', []];
		}
		$with = $set->mode !== 'all_readable' && $set->folderIds !== []
			? 'WITH RECURSIVE sub AS (
				SELECT fileid FROM *PREFIX*filecache WHERE fileid = ANY(?::bigint[])
				UNION
				SELECT c.fileid FROM *PREFIX*filecache c JOIN sub p ON c.parent = p.fileid WHERE c.parent > 0
			), cand AS (' . implode(' UNION ', $parts) . ')'
			: 'WITH cand AS (' . implode(' UNION ', $parts) . ')';
		$sql = $with . "
			SELECT DISTINCT f.fileid AS file_id
			FROM cand JOIN *PREFIX*filecache f ON f.fileid = cand.fileid
			JOIN *PREFIX*storages s ON s.numeric_id = f.storage
			JOIN *PREFIX*mimetypes m ON m.id = f.mimetype
			WHERE m.mimetype <> 'httpd/unix-directory'
			  AND (f.path = 'files' OR f.path LIKE 'files/%')
			  AND " . self::STORAGE_OK . '
			ORDER BY f.fileid';
		return [$sql, $params];
	}

	/** @return array{files: int, bytes: int} counts only: no names, no paths */
	public static function preview(IndexDb $db, ScopeSet $set): array {
		[$sql, $params] = self::eligible($set);
		$rows = $db->rows(
			'SELECT count(*) AS files, coalesce(sum(f.size), 0) AS bytes FROM (' . $sql . ') e JOIN *PREFIX*filecache f ON f.fileid = e.file_id',
			$params,
		);
		return ['files' => (int)$rows[0]['files'], 'bytes' => (int)$rows[0]['bytes']];
	}
}
