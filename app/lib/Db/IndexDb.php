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

	/**
	 * Runs $work in a transaction with a local statement timeout; the setting dies with the transaction, so it never
	 * leaks onto Nextcloud's shared connection.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function withTimeout(int $ms, callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('SET LOCAL statement_timeout = ' . max(1, $ms));
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * SQLSTATE 57014 (query_canceled): a statement timeout or an administrator's cancel. PDO writes the SQLSTATE
	 * untranslated whatever lc_messages says, and Nextcloud's DbalException keeps the driver message.
	 */
	public static function timedOut(\Throwable $e): bool {
		return str_contains($e->getMessage(), 'SQLSTATE[57014]');
	}

	/**
	 * Runs $work so that a failure cannot poison a transaction the caller is already in: Nextcloud nests transactions
	 * with savepoints, so this is a savepoint inside the request's transaction and a plain transaction outside one.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function guarded(callable $work): mixed {
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
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
