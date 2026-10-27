<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;

/** Schema 8 (whether a search snapshot holds an incomplete list) and its upgrade from schema 7. */
class SnapshotCappedSchemaTest extends DbTestCase {
	public function testUpgradeFromSchemaSevenAddsTheFlagAndKeepsSnapshotsUncapped(): void {
		$name = 'bd_t_up8_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql'] as $f) {
				foreach (SqlScript::load($f) as $st) {
					$app->exec($st);
				}
			}
			foreach ([SqlScript::upgradeToV2(), SqlScript::upgradeToV3(), SqlScript::upgradeToV4(), SqlScript::upgradeToV5(), SqlScript::upgradeToV6(), SqlScript::upgradeToV7()] as $step) {
				foreach ($step as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(7, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$app->exec("INSERT INTO aps_search.search_snapshot (qid, uid_hash, file_ids, chunk_ids) VALUES ('00000000-0000-4000-8000-000000000001', 'h', ARRAY[1]::bigint[], ARRAY[2]::bigint[])");
			foreach ([1, 2] as $_) {                                          // twice: idempotent
				foreach (SqlScript::upgradeToV8() as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(8, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$this->assertSame(1, (int)$app->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'aps_search' AND table_name = 'search_snapshot' AND column_name = 'capped'")->fetchColumn());
			$this->assertFalse((bool)$app->query('SELECT capped FROM aps_search.search_snapshot')->fetchColumn(), 'a snapshot from before the upgrade reads as complete');
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}
}
