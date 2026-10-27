<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;
use PDO;

/** Schema 6 (issue 64): the skip reason server_encrypted, and its upgrade from schema 5. */
class ServerEncryptedSchemaTest extends DbTestCase {
	public function testUpgradeFromSchemaFiveAcceptsServerEncryptedAndRunsTwice(): void {
		$name = 'bd_t_up6_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql'] as $f) {
				foreach (SqlScript::load($f) as $st) {
					$app->exec($st);
				}
			}
			foreach ([SqlScript::upgradeToV2(), SqlScript::upgradeToV3(), SqlScript::upgradeToV4(), SqlScript::upgradeToV5()] as $step) {
				foreach ($step as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(5, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			foreach ([1, 2] as $_) {
				foreach (SqlScript::upgradeToV6() as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(6, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());

			$app->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (1, 'e1', 'skipped', 'server_encrypted')");
			$app->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES (2, 'e1', 'pending')");
			$app->exec("INSERT INTO aps_search.job (lane, file_id, idem_key) VALUES ('text', 2, '2:e1')");
			$w = self::connect('bd_worker', 'dev-worker', $name);
			$claim = $this->claim($w);
			$w->prepare('SELECT aps_search.fail_job(?, ?, ?, false)')->execute([$claim['job_id'], $claim['lease_epoch'], 'server_encrypted']);
			$this->assertSame(
				[1 => 'server_encrypted', 2 => 'server_encrypted'],
				$app->query("SELECT file_id, skip_reason FROM aps_search.file_state WHERE text_state = 'skipped' ORDER BY file_id")->fetchAll(PDO::FETCH_KEY_PAIR),
				'the check constraint takes the reason on insert, and fail_job writes it',
			);
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}
}
