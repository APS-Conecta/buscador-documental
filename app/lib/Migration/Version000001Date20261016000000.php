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
 * Creates schema aps_search with raw SQL (S1 design C2): the ISchemaWrapper cannot express per-object grants,
 * SECURITY DEFINER functions or a dedicated owner role. Nextcloud's own tables are untouched.
 * The preflight step aborts before any object exists when the admin step was not done.
 */
class Version000001Date20261016000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * The SQL runs here, not in preSchemaChange: a first app:enable runs the schema step only (schemaOnly = true),
	 * so pre/postSchemaChange would be skipped and the app would be enabled without its index schema.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		foreach (SqlScript::up() as $statement) {
			$this->db->executeStatement($statement);
		}
		$output->info('aps_search schema created');
		return null;
	}
}
