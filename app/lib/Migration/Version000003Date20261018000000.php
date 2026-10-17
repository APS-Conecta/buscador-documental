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
 * Schema 3 (S4) for instances created at schema 1 or 2. A fresh install runs the first migration, whose SQL already includes
 * the S4 step, so this one finds schema_ver 3 and does nothing. Works in changeSchema like the others.
 */
class Version000003Date20261018000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$current = $this->db->executeQuery('SELECT schema_ver FROM aps_search.schema_meta')->fetchOne();
		if ($current === false || (int)$current >= 3) {
			return null;
		}
		if ((int)$current < 2) {
			SqlScript::runAtomically($this->db, SqlScript::upgradeToV2());
		}
		SqlScript::runAtomically($this->db, SqlScript::upgradeToV3());
		$output->info('aps_search upgraded to schema 3');
		return null;
	}
}
