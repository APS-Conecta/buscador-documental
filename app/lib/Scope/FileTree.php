<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

use OCP\IDBConnection;

/** Read-only view of Nextcloud's file cache for scope decisions. */
class FileTree {
	/** @var array<int, string> */
	private array $kinds = [];

	public function __construct(private IDBConnection $db) {
	}

	/** @return array{storage: int, path: string, parent: int}|null */
	public function node(int $fileId): ?array {
		$r = $this->db->executeQuery('SELECT storage, path, parent FROM *PREFIX*filecache WHERE fileid = ?', [$fileId])->fetch();
		return $r === false ? null : ['storage' => (int)$r['storage'], 'path' => (string)$r['path'], 'parent' => (int)$r['parent']];
	}

	public function isDirectory(int $fileId): bool {
		return $this->db->executeQuery(
			"SELECT 1 FROM *PREFIX*filecache f JOIN *PREFIX*mimetypes m ON m.id = f.mimetype WHERE f.fileid = ? AND m.mimetype = 'httpd/unix-directory'",
			[$fileId],
		)->fetchOne() !== false;
	}

	/**
	 * `home` (a user's own storage), `groupfolder` (a team folder's own storage, groupfolders >= 4) or `other`
	 * (external, object-store or shared-mount storages: no indexing guarantee in release 1).
	 */
	public function storageKind(int $storage): string {
		if (isset($this->kinds[$storage])) {
			return $this->kinds[$storage];
		}
		$id = $this->db->executeQuery('SELECT id FROM *PREFIX*storages WHERE numeric_id = ?', [$storage])->fetchOne();
		$id = $id === false ? '' : (string)$id;
		$kind = str_starts_with($id, 'home::') ? 'home' : (preg_match('#^local::/.+/__groupfolders/\d+/?$#', $id) === 1 ? 'groupfolder' : 'other');
		return $this->kinds[$storage] = $kind;
	}
}
