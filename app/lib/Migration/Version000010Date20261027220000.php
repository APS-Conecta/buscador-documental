<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schema 9 (the server_encrypted skip reason) for instances created at schema 1 to 8. A fresh install runs the first migration,
 * whose SQL already includes this step, so this one finds a schema_ver of 9 or more and does nothing. Works in changeSchema like the others.
 */
class Version000010Date20261027220000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$current = $this->db->executeQuery('SELECT schema_ver FROM aps_search.schema_meta')->fetchOne();
		if ($current === false || (int)$current >= 9) {
			return null;
		}
		if ((int)$current < 2) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV2());
		}
		if ((int)$current < 3) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV3());
		}
		if ((int)$current < 4) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV4());
		}
		if ((int)$current < 5) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV5());
		}
		if ((int)$current < 6) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV6());
		}
		if ((int)$current < 7) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV7());
		}
		if ((int)$current < 8) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV8());
		}
		SqlScript::runAtomically($this->db, SqlScript::upgradeToV9());
		$output->info('aps_search upgraded to schema 9');
		return null;
	}
}
