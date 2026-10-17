<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

use OCP\IDBConnection;

/**
 * Reads the facts for one file straight from oc_filecache / oc_share / the groupfolders tables.
 * Read-only and approximate by design: file_acl is a prefilter, the live recheck is the authority (ADR-0003).
 */
class FileFactsSource {
	private const MAX_DEPTH = 64;

	public function __construct(private IDBConnection $db) {
	}

	public function load(int $fileId): ?FileFacts {
		$row = $this->db->executeQuery(
			'SELECT f.fileid, f.name, f.path, f.etag, f.mtime, f.parent, m.mimetype, s.id AS storage_id
			 FROM *PREFIX*filecache f
			 JOIN *PREFIX*storages s ON s.numeric_id = f.storage
			 LEFT JOIN *PREFIX*mimetypes m ON m.id = f.mimetype
			 WHERE f.fileid = ?',
			[$fileId],
		)->fetch();
		if ($row === false) {
			return null;
		}
		$owner = str_starts_with((string)$row['storage_id'], 'home::') ? substr((string)$row['storage_id'], 6) : null;

		$ids = [$fileId];
		$parent = (int)$row['parent'];
		for ($depth = 0; $parent > 0 && $depth < self::MAX_DEPTH; $depth++) {
			$ids[] = $parent;
			$next = $this->db->executeQuery('SELECT parent FROM *PREFIX*filecache WHERE fileid = ?', [$parent])->fetchOne();
			$parent = $next === false ? 0 : (int)$next;
		}

		$users = [];
		$groups = [];
		$in = implode(',', array_fill(0, count($ids), '?'));
		$shares = $this->db->executeQuery(
			"SELECT share_type, share_with FROM *PREFIX*share WHERE share_type NOT IN (2, 3) AND file_source IN ($in)",
			$ids,
		)->fetchAll();
		$unmodelled = false;
		foreach ($shares as $share) {
			$type = (int)$share['share_type'];
			if ($type === 0) {
				$users[] = (string)$share['share_with'];
			} elseif ($type === 1) {
				$groups[] = (string)$share['share_with'];
			} elseif ($type !== 2 && $type !== 3) {
				$unmodelled = true; // anything but user, group, the per-member group rows (2) and anonymous link (3): mail, guest, federated, circle, Talk, Deck, ...
			}
		}

		$gfGroups = [];
		if (preg_match('#^__groupfolders/(\d+)/#', (string)$row['path'], $m) === 1) {
			$rows = $this->db->executeQuery(
				'SELECT group_id FROM *PREFIX*group_folders_groups WHERE folder_id = ? AND permissions > 0',
				[(int)$m[1]],
			)->fetchAll();
			foreach ($rows as $r) {
				$gfGroups[] = (string)$r['group_id'];
			}
			try {
				$acl = $this->db->executeQuery('SELECT acl FROM *PREFIX*group_folders WHERE folder_id = ?', [(int)$m[1]])->fetchOne();
				$unmodelled = $unmodelled || (int)$acl > 0; // advanced ACL rules can deny or grant per path
			} catch (\Throwable) {
				$unmodelled = true; // schema drift in groupfolders: let the live recheck decide
			}
		}

		return new FileFacts(
			$fileId,
			(string)$row['name'],
			(string)($row['mimetype'] ?? 'application/octet-stream'),
			(int)$row['mtime'],
			(string)$row['etag'],
			$owner,
			array_values(array_unique($users)),
			array_values(array_unique($groups)),
			array_values(array_unique($gfGroups)),
			$unmodelled,
		);
	}
}
