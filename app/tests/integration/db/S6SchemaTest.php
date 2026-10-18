<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;

/** S6 phase 1: schema 5 (owner of a file for the Propiedad filter) and its upgrade from schema 4. */
class S6SchemaTest extends DbTestCase {
	public function testOwnerColumnAndIndexExist(): void {
		$q = $this->app();
		$this->assertSame(1, (int)$q->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'aps_search' AND table_name = 'file_meta' AND column_name = 'owner'")->fetchColumn());
		$this->assertSame(1, (int)$q->query("SELECT count(*) FROM pg_indexes WHERE schemaname = 'aps_search' AND tablename = 'file_meta' AND indexdef ILIKE '%(owner)%'")->fetchColumn());
	}

	public function testUpgradeFromSchemaFourQueuesFilesWithoutAnOwnerOnceAndKeepsData(): void {
		$name = 'bd_t_up5_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql'] as $f) {
				foreach (SqlScript::load($f) as $st) {
					$app->exec($st);
				}
			}
			foreach ([SqlScript::upgradeToV2(), SqlScript::upgradeToV3(), SqlScript::upgradeToV4()] as $step) {
				foreach ($step as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(4, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$app->exec("INSERT INTO aps_search.file_meta (file_id, name, ancestors) VALUES (1, 'a.pdf', ARRAY[1]::bigint[])");
			$app->exec('DELETE FROM aps_search.outbox');
			foreach ([1, 2] as $_) {
				foreach (SqlScript::upgradeToV5() as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(5, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$this->assertSame(1, (int)$app->query("SELECT count(*) FROM aps_search.outbox WHERE file_id = 1 AND kind = 'file' AND processed_at IS NULL")->fetchColumn(), 'queued once, even after two runs');
			$this->assertSame(1, (int)$app->query('SELECT count(*) FROM aps_search.file_meta')->fetchColumn());
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}
}
