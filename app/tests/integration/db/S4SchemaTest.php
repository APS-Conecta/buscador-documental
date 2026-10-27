<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;
use OCA\BuscadorDocumental\Migration\Version000006Date20261027000000;
use OCP\Migration\IOutput;

/** S4 phase 1: schema 3 (generations, vectors, snapshots, filter data) and its upgrade from schema 2. */
class S4SchemaTest extends DbTestCase {
	protected function newGeneration(int $gen = 1, int $dim = 3, string $model = 'bge-m3:567m'): void {
		$this->app()->prepare('SELECT aps_search.create_generation(?, ?, ?, ?, ?, ?, ?, ?, ?)')
			->execute([$gen, 'bge-m3', $model, 'sha256:abc', $dim, 'f16', '', '', 8192]);
	}

	public function testSchemaVersionIsAtLeastThreeAndCurrent(): void {
		$this->assertSame(5, (int)$this->app()->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
		$this->assertSame(5, (int)$this->worker()->query('SELECT aps_search.schema_version()')->fetchColumn());
	}

	public function testNewTablesAndColumnsExist(): void {
		$q = $this->app();
		foreach (['embedding_profile', 'search_snapshot'] as $t) {
			$this->assertSame(1, (int)$q->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'aps_search' AND table_name = '$t'")->fetchColumn(), $t);
		}
		$this->assertSame('u', $q->query("SELECT CASE relpersistence WHEN 'u' THEN 'u' ELSE 'p' END FROM pg_class WHERE oid = 'aps_search.search_snapshot'::regclass")->fetchColumn(), 'snapshots are UNLOGGED');
		foreach ([['file_meta', 'ancestors'], ['job', 'gen']] as [$t, $c]) {
			$this->assertSame(1, (int)$q->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'aps_search' AND table_name = '$t' AND column_name = '$c'")->fetchColumn(), "$t.$c");
		}
	}

	public function testVecStateIsAClosedVocabulary(): void {
		$app = $this->app();
		foreach (['pending', 'embedding', 'embedded', 'skipped', 'failed'] as $i => $v) {
			$app->exec("INSERT INTO aps_search.file_state (file_id, text_state, vec_state) VALUES (" . (300 + $i) . ", 'indexed', '$v')");
		}
		$this->assertStringContainsString('violates check constraint', $this->failure(static fn () => $app->exec("INSERT INTO aps_search.file_state (file_id, text_state, vec_state) VALUES (399, 'indexed', 'maybe')")));
	}

	public function testCreateGenerationBuildsAPlainTableWithCascadeAndRegistersTheProfile(): void {
		$this->newGeneration(1, 3);
		$app = $this->app();
		$row = $app->query("SELECT state, dim, model, digest FROM aps_search.embedding_profile WHERE gen = 1")->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(['state' => 'building', 'dim' => 3, 'model' => 'bge-m3:567m', 'digest' => 'sha256:abc'], $row);
		$this->assertSame('r', $app->query("SELECT relkind FROM pg_class WHERE oid = 'aps_search.chunk_vec_1'::regclass")->fetchColumn());
		$this->assertSame('bd_owner', $app->query("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'aps_search.chunk_vec_1'::regclass")->fetchColumn());
		$this->assertStringContainsString('already exists', $this->failure(fn () => $this->newGeneration(1, 3)));
		$this->assertStringContainsString('dimension', $this->failure(fn () => $this->newGeneration(2, 0)));
		$this->assertStringContainsString('dimension', $this->failure(fn () => $this->newGeneration(3, 4001)));
	}

	public function testAChunkDeletedByAReindexTakesItsVectorsWithIt(): void {
		$this->newGeneration(1, 3);
		$app = $this->app();
		$app->exec("INSERT INTO aps_search.chunk (chunk_id, file_id, page_no, ord, text) VALUES (1, 10, 1, 0, 'a'), (2, 10, 1, 1, 'b')");
		$app->exec("INSERT INTO aps_search.chunk_vec_1 (chunk_id, file_id, vec) VALUES (1, 10, '[1,0,0]'), (2, 10, '[0,1,0]')");
		$app->exec('DELETE FROM aps_search.chunk WHERE file_id = 10');
		$this->assertSame(0, (int)$app->query('SELECT count(*) FROM aps_search.chunk_vec_1')->fetchColumn());
	}

	public function testGenerationLifecycleHasOneActiveAndNeverFlipsByItself(): void {
		$app = $this->app();
		$this->newGeneration(1, 3);
		$this->assertStringContainsString('not ready', $this->failure(static fn () => $app->exec('SELECT aps_search.activate_generation(1)')));
		$app->exec('SELECT aps_search.mark_generation_ready(1)');
		$app->exec('SELECT aps_search.activate_generation(1)');
		$this->newGeneration(2, 3);
		$app->exec('SELECT aps_search.mark_generation_ready(2)');
		$this->assertSame('1', (string)$app->query("SELECT gen FROM aps_search.embedding_profile WHERE state = 'active'")->fetchColumn(), 'a ready generation does not take over');
		$app->exec('SELECT aps_search.activate_generation(2)');
		$this->assertSame(['2:active', '1:retired'], $app->query("SELECT gen || ':' || state FROM aps_search.embedding_profile ORDER BY state = 'active' DESC")->fetchAll(\PDO::FETCH_COLUMN));
		$this->assertStringContainsString('active generation', $this->failure(static fn () => $app->exec('SELECT aps_search.drop_generation(2)')));
		$app->exec('SELECT aps_search.drop_generation(1)');
		$this->assertSame(0, (int)$app->query("SELECT count(*) FROM pg_class WHERE relname = 'chunk_vec_1'")->fetchColumn());
		$this->assertSame(1, (int)$app->query('SELECT count(*) FROM aps_search.embedding_profile')->fetchColumn());
	}

	public function testTheIndexBuildFunctionIsGoneBecauseOnlyAnAdministratorCommandBuildsTheIndex(): void {
		$this->assertTrue((bool)$this->app()->query("SELECT to_regprocedure('aps_search.build_vec_index(int)') IS NULL")->fetchColumn());
	}

	public function testUpgradeOfASchemaFiveInstanceDropsTheIndexBuildFunctionOnceAndKeepsTheVersion(): void {
		$name = 'bd_t_up6_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql', '004_s3.sql', '005_s4.sql', '006_s5.sql', '007_s6.sql'] as $f) {
				foreach (SqlScript::load($f) as $st) {
					$app->exec($st);
				}
			}
			$fn = static fn (): bool => (bool)$app->query("SELECT to_regprocedure('aps_search.build_vec_index(int)') IS NOT NULL")->fetchColumn();
			$this->assertTrue($fn(), 'an instance installed before the change');
			$step = new Version000006Date20261027000000($this->connection($app));
			foreach ([1, 2] as $_) {
				$step->changeSchema($this->createMock(IOutput::class), static fn () => null, []);
				$this->assertFalse($fn());
				$this->assertSame(5, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn(), 'the worker accepts exactly schema 5');
			}
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	public function testEraseFilesEmptiesEveryGeneration(): void {
		$this->newGeneration(1, 3);
		$this->newGeneration(2, 3);
		$app = $this->app();
		$app->exec("INSERT INTO aps_search.chunk (chunk_id, file_id, page_no, ord, text) VALUES (1, 10, 1, 0, 'a'), (2, 11, 1, 0, 'b')");
		foreach ([1, 2] as $g) {
			$app->exec("INSERT INTO aps_search.chunk_vec_$g (chunk_id, file_id, vec) VALUES (1, 10, '[1,0,0]'), (2, 11, '[0,1,0]')");
		}
		$app->exec("SELECT aps_search.erase_files(ARRAY[10]::bigint[], 'excluded')");
		foreach ([1, 2] as $g) {
			$this->assertSame([11], array_map('intval', $app->query("SELECT file_id FROM aps_search.chunk_vec_$g")->fetchAll(\PDO::FETCH_COLUMN)));
		}
	}

	public function testUpgradeFromSchemaTwoKeepsDataAndIsRepeatable(): void {
		$name = 'bd_t_up3_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');       // no vector: keyword-only installs must work
			$app = self::connect('nextcloud', 'dev', $name);
			foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql'] as $f) {
				foreach (SqlScript::load($f) as $st) {
					$app->exec($st);
				}
			}
			foreach (SqlScript::upgradeToV2() as $st) {
				$app->exec($st);
			}
			$this->assertSame(2, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$app->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES (1, 'e', 'indexed')");
			$app->exec("INSERT INTO aps_search.file_meta (file_id, name) VALUES (1, 'a.pdf')");
			foreach ([1, 2] as $_) {
				foreach (SqlScript::upgradeToV3() as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(3, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$this->assertSame(1, (int)$app->query('SELECT count(*) FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
			$this->assertSame(1, (int)$app->query("SELECT count(*) FROM aps_search.outbox WHERE file_id = 1 AND kind = 'file' AND processed_at IS NULL")->fetchColumn(), 'a file indexed before S4 is queued once so its folder chain gets filled in');
			$this->assertStringContainsString('vector', $this->failure(static fn () => $app->exec("SELECT aps_search.create_generation(1, 'p', 'm', 'd', 3, 'f16', '', '', 512)")), 'a missing pgvector is named when a profile is set, not at install');
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	public function testTheWorkerRoleHasNoPrivilegeOnAGenerationTable(): void {
		$this->newGeneration(1, 3);
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$this->assertFalse((bool)$admin->query("SELECT has_table_privilege('bd_worker', 'aps_search.chunk_vec_1', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')")->fetchColumn());
		$this->assertStringContainsString('permission denied', $this->failure(fn () => $this->worker()->exec('SELECT * FROM aps_search.chunk_vec_1')));
	}
}
