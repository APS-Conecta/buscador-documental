<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;
use OCA\BuscadorDocumental\Migration\Teardown;
use RuntimeException;

class MigrationTest extends DbTestCase {
	public function testPreflightNamesTheAdminStepWhenUnaccentIsMissing(): void {
		$name = 'bd_t_pre_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			$app = self::connect('nextcloud', 'dev', $name);
			$msg = $this->failure(static fn () => $app->exec(SqlScript::up()[0]));
			$this->assertStringContainsString('unaccent', $msg);
			$this->assertStringContainsString('administrator', $msg);
			$left = $app->query("SELECT count(*) FROM pg_namespace WHERE nspname = 'aps_search'")->fetchColumn();
			$this->assertSame(0, (int)$left, 'nothing may be created before the preflight passes');
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	public function testSchemaAndTextSearchConfigExist(): void {
		$app = $this->app();
		$this->assertSame(4, (int)$app->query("SELECT schema_ver FROM aps_search.schema_meta")->fetchColumn());
		$this->assertSame(1, (int)$app->query("SELECT count(*) FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE n.nspname = 'aps_search' AND c.cfgname = 'spanish_unaccent'")->fetchColumn());
		$this->assertSame(0, (int)$app->query("SELECT count(*) FROM pg_tables WHERE tablename LIKE 'oc\\_buscador%'")->fetchColumn());
	}

	public function testDownMigrationRefusesWhenFileStateHasRowsUnlessForced(): void {
		// Own database: the drop is destructive.
		$name = 'bd_t_down_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			foreach (SqlScript::up() as $st) {
				$app->exec($st);
			}
			$teardown = new Teardown($this->connection($app));
			$teardown->run(false);                                  // empty: allowed
			foreach (SqlScript::up() as $st) {
				$app->exec($st);
			}
			$app->exec("INSERT INTO aps_search.file_state (file_id, etag) VALUES (1, 'e')");
			try {
				$teardown->run(false);
				$this->fail('Expected refusal');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('refusing', $e->getMessage());
			}
			$this->assertSame(1, (int)$app->query('SELECT count(*) FROM aps_search.file_state')->fetchColumn());
			$teardown->run(true);
			$this->assertSame(0, (int)$app->query("SELECT count(*) FROM pg_namespace WHERE nspname = 'aps_search'")->fetchColumn());
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}
}
