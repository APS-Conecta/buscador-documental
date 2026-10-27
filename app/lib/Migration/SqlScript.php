<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Migration;

use OCP\IDBConnection;
use RuntimeException;
use Throwable;

/**
 * Splits a versioned .sql file into statements. The separator is a line holding only `-- @@`, because function
 * bodies contain semicolons and PDO's pgsql driver cannot prepare several statements at once.
 */
final class SqlScript {
	public const SEPARATOR = '-- @@';

	/** @return list<string> */
	public static function statements(string $sql): array {
		$out = [];
		foreach (preg_split('/^-- @@[ \t]*$/m', $sql) ?: [] as $part) {
			$part = trim($part);
			if ($part !== '' && !self::onlyComments($part)) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/** @return list<string> */
	public static function load(string $name): array {
		$path = __DIR__ . '/sql/' . $name;
		$sql = @file_get_contents($path);
		if ($sql === false) {
			throw new RuntimeException('Missing SQL step ' . $name);
		}
		return self::statements($sql);
	}

	/** @return list<string> the up steps in order */
	public static function up(): array {
		$all = [];
		foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql', '004_s3.sql', '005_s4.sql', '006_s5.sql', '007_s6.sql', '008_vec_index.sql', '009_give_up_guard.sql', '010_vec_failure.sql', '011_snapshot_capped.sql', '012_server_encrypted.sql'] as $f) {
			array_push($all, ...self::load($f));
		}
		return $all;
	}

	/** @return list<string> what an instance at schema_ver 1 needs: the S3 step alone (it is idempotent) */
	public static function upgradeToV2(): array {
		return self::load('004_s3.sql');
	}

	/** @return list<string> what an instance at schema_ver 2 needs: the S4 step alone (it is idempotent) */
	public static function upgradeToV3(): array {
		return self::load('005_s4.sql');
	}

	/** @return list<string> what an instance at schema_ver 3 needs: the S5 step alone (it is idempotent) */
	public static function upgradeToV4(): array {
		return self::load('006_s5.sql');
	}

	/** @return list<string> what an instance at schema_ver 4 needs: the S6 step alone (it is idempotent) */
	public static function upgradeToV5(): array {
		return self::load('007_s6.sql');
	}

	/** @return list<string> what an instance at schema_ver 5 needs: the give-up guard step alone (it is idempotent) */
	public static function upgradeToV6(): array {
		return self::load('009_give_up_guard.sql');
	}

	/** @return list<string> what an instance at schema_ver 6 needs: the vector failure step alone (it is idempotent) */
	public static function upgradeToV7(): array {
		return self::load('010_vec_failure.sql');
	}

	/** @return list<string> what an instance at schema_ver 7 needs: the snapshot capped flag step alone (it is idempotent) */
	public static function upgradeToV8(): array {
		return self::load('011_snapshot_capped.sql');
	}

	/** @return list<string> what an instance at schema_ver 8 needs: the server_encrypted step alone (it is idempotent) */
	public static function upgradeToV9(): array {
		return self::load('012_server_encrypted.sql');
	}

	/**
	 * Runs the statements all-or-nothing: PostgreSQL DDL is transactional, so a failure halfway leaves no half-built
	 * schema behind and the administrator can fix the cause and enable the app again.
	 *
	 * @param list<string> $statements
	 */
	public static function runAtomically(IDBConnection $db, array $statements): void {
		$db->beginTransaction();
		try {
			foreach ($statements as $statement) {
				$db->executeStatement($statement);
			}
			$db->commit();
		} catch (Throwable $e) {
			$db->rollBack();
			throw $e;
		}
	}

	private static function onlyComments(string $part): bool {
		foreach (explode("\n", $part) as $line) {
			$line = trim($line);
			if ($line !== '' && !str_starts_with($line, '--')) {
				return false;
			}
		}
		return true;
	}
}
