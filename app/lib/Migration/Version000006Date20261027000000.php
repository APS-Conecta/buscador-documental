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
 * Schema 6 (vector failures per generation) for instances created at schema 1 to 5. A fresh install runs the first migration,
 * whose SQL already includes this step, so this one finds schema_ver 6 and does nothing. Works in changeSchema like the others.
 */
class Version000006Date20261027000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$current = $this->db->executeQuery('SELECT schema_ver FROM aps_search.schema_meta')->fetchOne();
		if ($current === false || (int)$current >= 6) {
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
		SqlScript::runAtomically($this->db, SqlScript::upgradeToV6());
		$output->info('aps_search upgraded to schema 6');
		return null;
	}
}
