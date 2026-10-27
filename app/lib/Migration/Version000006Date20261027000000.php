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
 * Drops the index build function the background job used (008): the vector index is now built by `occ buscador:profile refresh`.
 * A fresh install already ran 008 in the first migration; the step is idempotent, so it runs whenever the schema exists.
 */
class Version000006Date20261027000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		if ($this->db->executeQuery('SELECT schema_ver FROM aps_search.schema_meta')->fetchOne() === false) {
			return null;
		}
		SqlScript::runAtomically($this->db, SqlScript::load('008_vec_index.sql'));
		return null;
	}
}
