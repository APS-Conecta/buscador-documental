<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Migration\SqlScript;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Applies the real migration SQL, as the Nextcloud DB user, to a scratch database inside the bd-s1 Postgres,
 * and offers connections as that user (bd_app), as bd_worker and as the admin. Roles come from db/init/00-roles.sql.
 */
abstract class DbTestCase extends TestCase {
	protected static string $dbName;
	private static ?PDO $admin = null;
	/** @var array<string, PDO> */
	private array $open = [];

	public static function setUpBeforeClass(): void {
		self::$dbName = 'bd_t_' . bin2hex(random_bytes(4));
		self::admin('nextcloud')->exec('CREATE DATABASE ' . self::$dbName . ' OWNER nextcloud');
		self::connect('postgres', 'dev', self::$dbName)->exec('CREATE EXTENSION unaccent; CREATE EXTENSION vector');
		$app = self::connect('nextcloud', 'dev', self::$dbName);
		foreach (SqlScript::up() as $st) {
			$app->exec($st);
		}
	}

	public static function tearDownAfterClass(): void {
		self::admin('nextcloud')->exec('DROP DATABASE IF EXISTS ' . self::$dbName . ' WITH (FORCE)');
	}

	protected function tearDown(): void {
		foreach ($this->open as $pdo) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
		}
		$this->open = [];
		$app = self::connect('postgres', 'dev', self::$dbName);
		$app->exec(<<<'SQL'
DO $$ DECLARE t text; BEGIN
	FOR t IN SELECT relname FROM pg_class WHERE relnamespace = 'aps_search'::regnamespace AND relkind = 'r' AND relname ~ '^chunk_vec_[0-9]+$' LOOP
		EXECUTE format('DROP TABLE aps_search.%I', t);
	END LOOP;
END $$;
SQL);
		$app->exec('TRUNCATE aps_search.job, aps_search.page, aps_search.chunk, aps_search.file_state, aps_search.file_meta, aps_search.file_acl, aps_search.outbox, aps_search.tombstone, aps_search.audit_log, aps_search.scope_rule, aps_search.reconcile_run, aps_search.purge_pending, aps_search.pseudonym, aps_search.reconcile_node, aps_search.worker_seen RESTART IDENTITY; DROP TABLE IF EXISTS oc_filecache, oc_storages, oc_group_folders, oc_group_folders_groups, oc_group_folders_acl, oc_mimetypes, oc_systemtag, oc_systemtag_object_mapping; DELETE FROM aps_search.settings');
		$app->exec('DELETE FROM aps_search.embedding_profile; TRUNCATE aps_search.search_snapshot');
	}

	protected static function admin(string $db): PDO {
		return self::$admin ??= self::connect('postgres', 'dev', $db);
	}

	protected static function connect(string $user, string $pass, string $db): PDO {
		$host = getenv('BD_PG_HOST') ?: '127.0.0.1';
		$pdo = new PDO("pgsql:host=$host;dbname=$db", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec("SET lock_timeout = '5s'");
		return $pdo;
	}

	/** A fresh session as the Nextcloud DB user. */
	protected function app(): PDO {
		return $this->open[] = self::connect('nextcloud', 'dev', self::$dbName);
	}

	/** A fresh session as the worker role. */
	protected function worker(): PDO {
		return $this->open[] = self::connect('bd_worker', 'dev-worker', self::$dbName);
	}

	/** Queues one file the way FileIndexer does and returns nothing. */
	protected function seedFile(int $fileId, string $etag = 'e1', ?string $hash = null): void {
		$this->app()->prepare("INSERT INTO aps_search.file_state (file_id, etag, content_hash, text_state) VALUES (?, ?, ?, 'pending')")
			->execute([$fileId, $etag, $hash]);
		$this->app()->prepare("INSERT INTO aps_search.job (lane, file_id, idem_key) VALUES ('text', ?, ?)")
			->execute([$fileId, "$fileId:$etag"]);
	}

	/** @return array{job_id:int, lease_epoch:int, file_id:int, etag:string}|null */
	protected function claim(PDO $pdo, string $who = 'w1', int $lease = 900): ?array {
		$s = $pdo->prepare('SELECT * FROM aps_search.claim_job(?, 1, ?, ?)');
		$s->execute(['text', $who, $lease]);
		$r = $s->fetch(PDO::FETCH_ASSOC);
		return $r === false ? null : array_map(static fn ($v) => is_numeric($v) ? (int)$v : $v, $r);
	}

	protected function write(PDO $pdo, array $claim, array $over = []): void {
		$p = array_merge([
			'job' => $claim['job_id'], 'epoch' => $claim['lease_epoch'], 'etag' => $claim['etag'], 'hash' => 'h1',
			'pages' => json_encode([['page_no' => 1, 'text' => 'hola mundo', 'ocr_conf' => 0.9, 'source' => 'ocr']]),
			'chunks' => json_encode([['page_no' => 1, 'ord' => 0, 'heading' => null, 'text' => 'hola mundo']]),
			'ocr_ver' => 'ocrmypdf-test', 'chunker_ver' => 'chunker-test',
		], $over);
		$s = $pdo->prepare('SELECT aps_search.write_result(?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?)');
		$s->execute([$p['job'], $p['epoch'], $p['etag'], $p['hash'], $p['pages'], $p['chunks'], $p['ocr_ver'], $p['chunker_ver']]);
	}

	protected function rowCount(string $table, ?int $fileId = null): int {
		$sql = "SELECT count(*) FROM aps_search.$table" . ($fileId === null ? '' : ' WHERE file_id = ' . $fileId);
		return (int)self::connect('nextcloud', 'dev', self::$dbName)->query($sql)->fetchColumn();
	}

	/** Runs $fn and returns the message of the PDOException it raises (fails the test if none). */
	protected function failure(callable $fn): string {
		try {
			$fn();
		} catch (PDOException $e) {
			return $e->getMessage();
		}
		$this->fail('Expected a database error');
	}

	/** An IDBConnection backed by PDO, for the few classes that take Nextcloud's connection. */
	protected function connection(PDO $pdo): IDBConnection {
		$run = static function (string $sql, array $params) use ($pdo) {
			$s = $pdo->prepare(str_replace('*PREFIX*', 'oc_', $sql));            // what Nextcloud's connection does
			$s->execute($params);
			return $s;
		};
		$c = $this->createMock(IDBConnection::class);
		$c->method('executeQuery')->willReturnCallback(function (string $sql, array $params = []) use ($run) {
			$s = $run($sql, $params);
			$r = $this->createMock(IResult::class);
			$r->method('fetchOne')->willReturnCallback(static fn () => $s->fetchColumn());
			$r->method('fetch')->willReturnCallback(static fn () => $s->fetch(PDO::FETCH_ASSOC));
			$r->method('fetchAll')->willReturnCallback(static fn () => $s->fetchAll(PDO::FETCH_ASSOC));
			return $r;
		});
		$c->method('executeStatement')->willReturnCallback(static fn (string $sql, array $params = []) => $run($sql, $params)->rowCount());
		// Nextcloud nests transactions with savepoints (OC\DB\Connection: setNestTransactionsWithSavepoints): emulate that
		$levels = [];                                                         // true = this level opened the real transaction
		$c->method('inTransaction')->willReturnCallback(static fn () => $pdo->inTransaction());
		$c->method('beginTransaction')->willReturnCallback(static function () use ($pdo, &$levels): void {
			if ($pdo->inTransaction()) {
				$pdo->exec('SAVEPOINT sp_' . count($levels));
				$levels[] = false;
			} else {
				$pdo->beginTransaction();
				$levels[] = true;
			}
		});
		$c->method('commit')->willReturnCallback(static function () use ($pdo, &$levels): void {
			$owns = array_pop($levels);
			$owns === false ? $pdo->exec('RELEASE SAVEPOINT sp_' . count($levels)) : $pdo->commit();
		});
		$c->method('rollBack')->willReturnCallback(static function () use ($pdo, &$levels): void {
			$owns = array_pop($levels);
			if ($owns === false) {
				$pdo->exec('ROLLBACK TO SAVEPOINT sp_' . count($levels));
				$pdo->exec('RELEASE SAVEPOINT sp_' . count($levels));
			} else {
				$pdo->rollBack();
			}
		});
		return $c;
	}
}
