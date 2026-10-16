<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Migration;

use RuntimeException;

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
		foreach (['000_preflight.sql', '001_schema.sql', '002_functions.sql', '003_roles.sql'] as $f) {
			array_push($all, ...self::load($f));
		}
		return $all;
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
