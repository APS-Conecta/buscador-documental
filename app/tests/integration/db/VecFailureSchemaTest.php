<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;
use PDO;

/** Schema 6: permanent vector failures recorded per generation, and the upgrade from schema 5 that seeds them from vec_state. */
class VecFailureSchemaTest extends DbTestCase {
	/**
	 * Builds a schema 5 database, lets $seed fill it, then applies the schema 6 step twice.
	 *
	 * @param callable(PDO): void $seed
	 * @param bool $vector whether pgvector is installed (a keyword-only install has none)
	 * @return array{ver: int, records: list<string>, before: list<string>, after: list<string>}
	 */
	private function upgradeFromFive(callable $seed, bool $vector = true): array {
		$name = 'bd_t_up6_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent' . ($vector ? '; CREATE EXTENSION vector' : ''));
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
			$seed($app);
			$states = static fn (): array => $app->query("SELECT file_id || ':' || text_state || ':' || vec_state FROM aps_search.file_state ORDER BY file_id")->fetchAll(PDO::FETCH_COLUMN);
			$before = $states();
			foreach ([1, 2] as $_) {
				foreach (SqlScript::upgradeToV6() as $st) {
					$app->exec($st);
				}
			}
			return [
				'ver' => (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn(),
				'records' => $app->query("SELECT file_id || ':' || gen FROM aps_search.vec_failure ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN),
				'before' => $before,
				'after' => $states(),
			];
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	private static function generation(PDO $app, int $gen, string $state): void {
		$app->exec("SELECT aps_search.create_generation($gen, 'bge-m3', 'bge-m3:567m', 'sha256:g$gen', 3, 'f16', '', '', 8192)");
		if ($state !== 'building') {
			$app->exec("SELECT aps_search.mark_generation_ready($gen)");
		}
		if ($state === 'active') {
			$app->exec("SELECT aps_search.activate_generation($gen)");
		}
	}

	private static function files(PDO $app): void {
		$app->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, vec_state) VALUES
			(1, 'e', 'indexed', 'failed'), (2, 'e', 'indexed', 'embedded'), (3, 'e', 'indexed', 'pending'), (4, 'e', 'skipped', 'failed')");
	}

	public function testTheUpgradeRecordsTheFailuresVecStateHeldForTheActiveGeneration(): void {
		$r = $this->upgradeFromFive(static function (PDO $app): void {
			self::generation($app, 1, 'active');
			self::generation($app, 2, 'active');                            // generation 1 is retired
			self::generation($app, 3, 'building');
			self::files($app);
		});
		$this->assertSame(6, $r['ver']);
		$this->assertSame(['1:2', '4:2'], $r['records'], 'vec_state described generation 2 only');
		$this->assertSame($r['before'], $r['after'], 'no state is changed');
	}

	public function testWithoutAnActiveGenerationTheFailuresCountForEveryLiveOne(): void {
		$r = $this->upgradeFromFive(static function (PDO $app): void {
			self::generation($app, 1, 'ready');
			self::generation($app, 2, 'building');
			self::files($app);
		});
		$this->assertSame(['1:1', '1:2', '4:1', '4:2'], $r['records']);
		$this->assertSame($r['before'], $r['after']);
	}

	public function testAKeywordOnlyInstallUpgradesWithNothingToRecord(): void {
		$r = $this->upgradeFromFive(self::files(...), vector: false);
		$this->assertSame([6, []], [$r['ver'], $r['records']]);
	}
}
