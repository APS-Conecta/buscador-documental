<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Db;

use OCP\IDBConnection;

/** Thin access to schema aps_search through Nextcloud's connection. Positional `?` parameters only. */
class IndexDb {
	public function __construct(private IDBConnection $db) {
	}

	public function execute(string $sql, array $params = []): int {
		return (int)$this->db->executeStatement($sql, $params);
	}

	/** @return list<array<string, mixed>> */
	public function rows(string $sql, array $params = []): array {
		return $this->db->executeQuery($sql, $params)->fetchAll();
	}

	/** Postgres array literal for a text[] parameter (use as `?::text[]`). */
	public static function textArray(array $values): string {
		$items = array_map(
			static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"',
			array_values(array_map('strval', $values)),
		);
		return '{' . implode(',', $items) . '}';
	}
}
