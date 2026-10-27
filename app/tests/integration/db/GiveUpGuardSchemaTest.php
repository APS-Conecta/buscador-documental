<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;
use PDO;

/** Schema 6: the etag guard on the give-ups of fail_job and claim_job, and its upgrade from schema 5. */
class GiveUpGuardSchemaTest extends DbTestCase {
	private const GUARDED = ['aps_search.fail_job(bigint,bigint,text,boolean)', 'aps_search.claim_job(text,int,text,int)'];

	public function testUpgradeFromSchemaFiveEndsWithTheFreshInstallDefinitions(): void {
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
			foreach ([1, 2] as $_) {                                          // twice: idempotent
				foreach (SqlScript::upgradeToV6() as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(6, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			foreach ([SqlScript::upgradeToV7(), SqlScript::upgradeToV8(), SqlScript::upgradeToV9()] as $step) {   // as a fresh install runs them: 7 redefines both functions, 9 fail_job
				foreach ($step as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame($this->definitions($this->app()), $this->definitions($app), 'an upgraded instance runs what a fresh install runs');
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	/** @return list<string> */
	private function definitions(PDO $db): array {
		$s = $db->prepare('SELECT pg_get_functiondef(?::regprocedure)');
		return array_map(static function (string $fn) use ($s): string {
			$s->execute([$fn]);
			return (string)$s->fetchColumn();
		}, self::GUARDED);
	}
}
