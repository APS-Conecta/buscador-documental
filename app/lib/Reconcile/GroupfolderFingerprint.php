<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Reconcile;

use OCA\BuscadorDocumental\Db\IndexDb;
use Throwable;

/**
 * Team-folder grants and advanced-ACL rules change without any typed event (only free-text audit events), so a poll
 * fingerprints them. A moved fingerprint marks the folder's indexed files for a principals refresh; the live recheck
 * protects reads in the meantime. The first poll only records a baseline; the weekly full refresh covers older changes.
 */
class GroupfolderFingerprint {
	public function __construct(private IndexDb $db) {
	}

	/** @return int number of team folders whose files were marked for refresh */
	public function check(): int {
		try {
			$folders = $this->db->rows("SELECT g.folder_id, g.storage_id, g.acl,
				coalesce((SELECT md5(string_agg(gg.group_id || ':' || gg.permissions, ',' ORDER BY gg.group_id)) FROM *PREFIX*group_folders_groups gg WHERE gg.folder_id = g.folder_id), '') AS grants
				FROM *PREFIX*group_folders g ORDER BY g.folder_id");
			$rules = (string)$this->db->rows("SELECT coalesce(md5(string_agg(a.fileid || ':' || a.mapping_type || ':' || a.mapping_id || ':' || a.mask || ':' || a.permissions, ',' ORDER BY a.fileid, a.mapping_type, a.mapping_id)), '') AS fp FROM *PREFIX*group_folders_acl a")[0]['fp'];
		} catch (Throwable) {
			return 0;                                   // the app is not installed (or its schema moved): nothing to watch
		}
		$stored = [];
		foreach ($this->db->rows("SELECT key, value FROM aps_search.settings WHERE key LIKE 'gf\\_fp:%'") as $r) {
			$stored[(string)$r['key']] = (string)$r['value'];
		}
		$rulesMoved = isset($stored['gf_fp:rules']) && $stored['gf_fp:rules'] !== $rules;
		$marked = 0;
		$seen = [];
		foreach ($folders as $f) {
			$key = 'gf_fp:' . (int)$f['folder_id'];
			$seen[$key] = true;
			$fp = md5($f['storage_id'] . '|' . $f['acl'] . '|' . $f['grants']);
			$known = $stored[$key] ?? null;
			if ($known !== null && ($known !== $fp || $rulesMoved)) {
				$this->db->execute(
					'UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = coalesce(acl_dirty_since, now())
					 WHERE file_id IN (SELECT file_id FROM aps_search.file_meta WHERE storage_id = ?)',
					[(int)$f['storage_id']],
				);
				$marked++;
			}
			if ($known !== $fp) {
				$this->put($key, $fp);
			}
		}
		if (($stored['gf_fp:rules'] ?? null) !== $rules) {
			$this->put('gf_fp:rules', $rules);
		}
		foreach (array_keys($stored) as $key) {
			if ($key !== 'gf_fp:rules' && !isset($seen[$key])) {
				$this->db->execute('DELETE FROM aps_search.settings WHERE key = ?', [$key]);        // the folder is gone
			}
		}
		return $marked;
	}

	private function put(string $key, string $value): void {
		$this->db->execute('INSERT INTO aps_search.settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value', [$key, $value]);
	}
}
