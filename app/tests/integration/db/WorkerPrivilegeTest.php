<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

/** A-SEC-4, part 1: the worker role has no direct DML, and reaches the index only through four functions. */
class WorkerPrivilegeTest extends DbTestCase {
	public function testWorkerHoldsNoPrivilegeOnAnyTable(): void {
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$tables = $admin->query("SELECT tablename FROM pg_tables WHERE schemaname = 'aps_search'")->fetchAll(\PDO::FETCH_COLUMN);
		$this->assertGreaterThanOrEqual(7, count($tables));
		foreach ($tables as $t) {
			$has = $admin->query("SELECT has_table_privilege('bd_worker', 'aps_search.$t', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')")->fetchColumn();
			$this->assertFalse((bool)$has, "bd_worker must hold no privilege on $t");
		}
	}

	public function testWorkerCannotRunDmlOnAnyTable(): void {
		$w = $this->worker();
		$tables = ['schema_meta' => 'schema_ver', 'file_state' => 'file_id', 'job' => 'file_id', 'page' => 'file_id', 'chunk' => 'file_id', 'file_meta' => 'file_id', 'file_acl' => 'file_id'];
		foreach ($tables as $t => $col) {
			foreach (["SELECT * FROM aps_search.$t", "DELETE FROM aps_search.$t", "UPDATE aps_search.$t SET $col = $col"] as $sql) {
				$this->assertStringContainsString('permission denied', $this->failure(static fn () => $w->exec($sql)), "$t: $sql");
			}
		}
		$this->assertStringContainsString('permission denied', $this->failure(static fn () => $w->exec("INSERT INTO aps_search.page (file_id, page_no, text) VALUES (1, 1, 'x')")));
		$this->assertStringContainsString('permission denied', $this->failure(static fn () => $w->exec('CREATE TABLE aps_search.evil (a int)')));
		$this->assertStringContainsString('permission denied', $this->failure(static fn () => $w->exec('CREATE FUNCTION aps_search.evil() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$')));
	}

	public function testWorkerExecutesExactlyFourFunctions(): void {
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$rows = $admin->query("SELECT p.proname, p.prosecdef, has_function_privilege('bd_worker', p.oid, 'EXECUTE') AS can, pg_get_userbyid(p.proowner) AS owner, p.proconfig::text AS cfg
			FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'aps_search' ORDER BY 1")->fetchAll(\PDO::FETCH_ASSOC);
		$can = [];
		foreach ($rows as $r) {
			$this->assertTrue((bool)$r['prosecdef'], $r['proname'] . ' must be SECURITY DEFINER');
			$this->assertSame('bd_owner', $r['owner']);
			$this->assertStringContainsString('search_path=aps_search, pg_catalog, pg_temp', $r['cfg']);
			if ($r['can']) {
				$can[] = $r['proname'];
			}
		}
		$this->assertSame(['claim_job', 'fail_job', 'heartbeat', 'write_result'], $can);
		$this->assertStringContainsString('permission denied', $this->failure(fn () => $this->worker()->exec("SELECT aps_search.erase_file(1, 'x')")));
	}

	public function testNextcloudUserHasDmlButCannotAlterSchemaOrRoles(): void {
		$app = $this->app();
		$app->exec("INSERT INTO aps_search.file_meta (file_id, name) VALUES (1, 'a')");
		$this->assertStringContainsString('permission denied', $this->failure(static fn () => $app->exec('CREATE TABLE aps_search.evil (a int)')));
		$this->assertStringContainsString('permission denied', $this->failure(static fn () => $app->exec('CREATE ROLE evil')));
	}

	public function testBdOwnerOwnsNothingOutsideItsSchema(): void {
		$n = self::connect('postgres', 'dev', self::$dbName)->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
			WHERE pg_get_userbyid(c.relowner) = 'bd_owner' AND n.nspname NOT IN ('aps_search', 'pg_toast')")->fetchColumn();
		$this->assertSame(0, (int)$n);
	}
}
