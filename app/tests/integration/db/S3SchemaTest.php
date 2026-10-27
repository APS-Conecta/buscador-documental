<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;

/** S3 phase 1: the new tables, the batch erase primitives, the worker's schema check, and the upgrade from schema 1. */
class S3SchemaTest extends DbTestCase {
	private function indexed(int $id): void {
		$app = $this->app();
		$this->seedFile($id);
		$w = $this->worker();
		$this->write($w, $this->claim($w, 'w' . $id));
		$app->exec("INSERT INTO aps_search.file_acl (file_id, principals) VALUES ($id, ARRAY['u:a'])");
		$app->exec("INSERT INTO aps_search.file_meta (file_id, name, storage_id) VALUES ($id, 'f$id.pdf', 7)");
	}

	public function testNewTablesAndColumnsExist(): void {
		$q = $this->app();
		foreach (['outbox', 'tombstone', 'audit_log', 'scope_rule', 'reconcile_run', 'settings', 'purge_pending', 'pseudonym'] as $t) {
			$this->assertSame(1, (int)$q->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'aps_search' AND table_name = '$t'")->fetchColumn(), $t);
		}
		foreach ([['file_state', 'acl_dirty_since'], ['file_state', 'reconciled_at'], ['file_meta', 'storage_id']] as [$t, $c]) {
			$this->assertSame(1, (int)$q->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'aps_search' AND table_name = '$t' AND column_name = '$c'")->fetchColumn(), "$t.$c");
		}
	}

	public function testSkipReasonIsAClosedVocabulary(): void {
		$app = $this->app();
		foreach (['encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout', 'internal', 'unsafe', 'excluded', 'out_of_scope', 'trashed', 'user_deleted'] as $i => $r) {
			$app->exec("INSERT INTO aps_search.file_state (file_id, text_state, skip_reason) VALUES (" . (100 + $i) . ", 'skipped', '$r')");
		}
		$this->assertStringContainsString('violates check constraint', $this->failure(static fn () => $app->exec("INSERT INTO aps_search.file_state (file_id, text_state, skip_reason) VALUES (200, 'skipped', 'because I said so')")));
	}

	public function testOutboxDeduplicatesUnprocessedRowsOnly(): void {
		$app = $this->app();
		$ins = "INSERT INTO aps_search.outbox (file_id, kind) VALUES (5, 'file') ON CONFLICT DO NOTHING";
		$app->exec($ins);
		$app->exec($ins);
		$this->assertSame(1, $this->rowCount('outbox', 5));
		$app->exec("UPDATE aps_search.outbox SET processed_at = now() WHERE file_id = 5");
		$app->exec($ins);
		$this->assertSame(2, $this->rowCount('outbox', 5), 'a processed row does not block a new event');
		$this->assertStringContainsString('violates check constraint', $this->failure(static fn () => $app->exec("INSERT INTO aps_search.outbox (file_id, kind) VALUES (5, 'nonsense')")));
	}

	public function testEraseFilesDeletesEverythingForEveryFileAndKeepsAVisibleState(): void {
		foreach ([10, 11, 12] as $id) {
			$this->indexed($id);
		}
		$this->indexed(99);                                                  // untouched neighbour
		$app = $this->app();
		$app->exec('SET ROLE bd_owner');
		$app->exec('CREATE TABLE aps_search.chunk_vec_9 (chunk_id bigint, file_id bigint)');     // an S4 generation table, owned like the others
		$app->exec('GRANT ALL ON aps_search.chunk_vec_9 TO SESSION_USER');
		$app->exec('RESET ROLE');
		$app->exec('INSERT INTO aps_search.chunk_vec_9 SELECT chunk_id, file_id FROM aps_search.chunk');
		$tables = ['page', 'chunk', 'file_acl', 'file_meta', 'chunk_vec_9'];
		$before = [];
		foreach ($tables as $t) {
			$before[$t] = $this->rowCount($t, 99);
			$this->assertSame(1, $before[$t], "$t: the neighbour has a row to protect");
		}
		$app->exec("SELECT aps_search.erase_files(ARRAY[10, 11, 12]::bigint[], 'excluded')");
		foreach ($tables as $t) {
			foreach ([10, 11, 12] as $id) {
				$this->assertSame(0, $this->rowCount($t, $id), "$t $id");
			}
			$this->assertSame($before[$t], $this->rowCount($t, 99), "$t 99 must be untouched");
		}
		$st = $app->query("SELECT text_state || ':' || skip_reason FROM aps_search.file_state WHERE file_id = 10")->fetchColumn();
		$this->assertSame('skipped:excluded', $st);
	}

	public function testEraseFilesRefusesAFreeTextReason(): void {
		$this->indexed(10);
		$this->assertStringContainsString('violates check constraint', $this->failure(fn () => $this->app()->exec("SELECT aps_search.erase_files(ARRAY[10]::bigint[], 'because')")));
		$this->assertSame(1, $this->rowCount('chunk', 10), 'a refused erase changes nothing');
	}

	public function testPurgeFilesAlsoRemovesTheState(): void {
		$this->indexed(10);
		$this->app()->exec("SELECT aps_search.purge_files(ARRAY[10]::bigint[])");
		$this->assertSame(0, $this->rowCount('file_state', 10));
		$this->assertSame(0, $this->rowCount('chunk', 10));
	}

	public function testEraseAndPurgeForgetTheFilesVectorFailures(): void {
		foreach ([10, 11, 99] as $id) {
			$this->indexed($id);
		}
		$app = $this->app();
		$app->exec('INSERT INTO aps_search.vec_failure (file_id, gen) VALUES (10, 1), (10, 2), (11, 1), (99, 1)');
		$app->exec("SELECT aps_search.erase_files(ARRAY[10]::bigint[], 'excluded')");
		$this->assertSame(0, $this->rowCount('vec_failure', 10), 'erasure leaves no trace');
		$app->exec('SELECT aps_search.purge_files(ARRAY[11]::bigint[])');
		$this->assertSame(0, $this->rowCount('vec_failure', 11), 'nor does a purge');
		$this->assertSame(1, $this->rowCount('vec_failure', 99), 'another file keeps its record');
	}

	public function testEraseFileStaysAsAWrapperForOneFile(): void {
		$this->indexed(10);
		$this->app()->exec("SELECT aps_search.erase_file(10, 'excluded')");
		$this->assertSame(0, $this->rowCount('chunk', 10));
	}

	public function testSchemaVersionIsReadableByTheWorker(): void {
		$this->assertSame(6, (int)$this->worker()->query('SELECT aps_search.schema_version()')->fetchColumn());
	}

	public function testNextcloudUserCanUseTheNewTables(): void {
		$app = $this->app();
		$app->exec("INSERT INTO aps_search.audit_log (actor, action, subject_token, detail) VALUES ('t1', 'scope.add', 't2', '{\"rule\": 1}')");
		$app->exec("INSERT INTO aps_search.tombstone (file_id, reason, purge_after) VALUES (5, 'trashed', now() + interval '30 days')");
		$app->exec("INSERT INTO aps_search.scope_rule (kind, file_id, created_by_token) VALUES ('folder', 7, 't1')");
		$app->exec("INSERT INTO aps_search.settings (key, value) VALUES ('scope_mode', 'opt_in')");
		$app->exec("INSERT INTO aps_search.pseudonym (uid, token) VALUES ('alice', 'tok')");
		$this->assertStringContainsString('violates check constraint', $this->failure(static fn () => $app->exec("INSERT INTO aps_search.scope_rule (kind, file_id) VALUES ('everything', 1)")));
		$this->assertStringContainsString('duplicate key', $this->failure(static fn () => $app->exec("INSERT INTO aps_search.pseudonym (uid, token) VALUES ('bob', 'tok')")));
	}

	/** Nextcloud keeps using the connection after the migration: its next statement must still see its own tables. */
	public function testTheMigrationLeavesSearchPathAndRoleAsItFoundThem(): void {
		$name = 'bd_t_sess_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			$before = $app->query('SHOW search_path')->fetchColumn();
			foreach (SqlScript::up() as $st) {
				$app->exec($st);
			}
			$this->assertSame($before, $app->query('SHOW search_path')->fetchColumn(), 'search_path after a fresh install');
			$this->assertSame('nextcloud', $app->query('SELECT current_user')->fetchColumn(), 'role after a fresh install');
			foreach (SqlScript::upgradeToV2() as $st) {
				$app->exec($st);
			}
			$this->assertSame($before, $app->query('SHOW search_path')->fetchColumn(), 'search_path after an upgrade');
			$this->assertSame('nextcloud', $app->query('SELECT current_user')->fetchColumn(), 'role after an upgrade');
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	public function testAMigrationThatFailsHalfwayLeavesNothingBehind(): void {
		$name = 'bd_t_atomic_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			$statements = [...SqlScript::up(), 'SELECT * FROM aps_search.no_such_table'];
			$this->assertStringContainsString('no_such_table', $this->failure(fn () => SqlScript::runAtomically($this->connection($app), $statements)));
			$this->assertSame(0, (int)$app->query("SELECT count(*) FROM pg_namespace WHERE nspname = 'aps_search'")->fetchColumn(), 'no half-built schema');
			$this->assertSame('nextcloud', $app->query('SELECT current_user')->fetchColumn());
			SqlScript::runAtomically($this->connection($app), SqlScript::up());           // and a retry now works
			$this->assertSame(6, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	public function testUpgradeFromSchemaOneKeepsDataAndIsRepeatable(): void {
		$name = 'bd_t_up_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			$all = SqlScript::up();
			$v1 = [];
			foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql'] as $f) {
				array_push($v1, ...SqlScript::load($f));
			}
			foreach ($v1 as $st) {
				$app->exec($st);
			}
			$app->exec("UPDATE aps_search.schema_meta SET schema_ver = 1");
			$app->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES (1, 'e', 'indexed')");
			foreach ([1, 2] as $_) {                                          // twice: idempotent
				foreach (SqlScript::upgradeToV2() as $st) {
					$app->exec($st);
				}
			}
			$this->assertSame(2, (int)$app->query('SELECT schema_ver FROM aps_search.schema_meta')->fetchColumn());
			$this->assertSame(1, (int)$app->query('SELECT count(*) FROM aps_search.file_state WHERE file_id = 1')->fetchColumn(), 'existing data survives');
			$app->exec("INSERT INTO aps_search.outbox (file_id, kind) VALUES (1, 'file')");
			$this->assertGreaterThan(count($v1), count($all));
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}
}
