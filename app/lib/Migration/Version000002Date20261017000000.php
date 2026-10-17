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
 * Schema 2 (S3) for instances created at schema 1. A fresh install runs the first migration, whose SQL already includes
 * the S3 step, so this one finds schema_ver 2 and does nothing. Like the first migration it works in changeSchema,
 * the only step a first enable is guaranteed to run.
 */
class Version000002Date20261017000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$current = $this->db->executeQuery('SELECT schema_ver FROM aps_search.schema_meta')->fetchOne();
		if ($current === false || (int)$current >= 2) {
			return null;
		}
		SqlScript::runAtomically($this->db, SqlScript::upgradeToV2());
		$output->info('aps_search upgraded to schema 2');
		return null;
	}
}
