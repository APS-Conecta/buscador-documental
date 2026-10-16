<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Migration;

use OCP\IDBConnection;
use RuntimeException;

/** The down migration: drops aps_search, but refuses while file_state holds rows unless forced (D2). */
class Teardown {
	public function __construct(private IDBConnection $db) {
	}

	public function run(bool $force): void {
		$exists = $this->db->executeQuery("SELECT 1 FROM pg_namespace WHERE nspname = 'aps_search'")->fetchOne();
		if ($exists === false) {
			return;
		}
		$rows = (int)$this->db->executeQuery('SELECT count(*) FROM aps_search.file_state')->fetchOne();
		if ($rows > 0 && !$force) {
			throw new RuntimeException("aps_search.file_state holds $rows rows; refusing to drop the index without --force");
		}
		foreach (SqlScript::load('900_down.sql') as $statement) {
			$this->db->executeStatement($statement);
		}
	}
}
