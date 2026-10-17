<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use InvalidArgumentException;
use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * The candidate legs of a search (S4 design section 3): one best chunk per file, best first, under the stored-principals
 * prefilter and the filters. The live recheck decides afterwards; nothing here knows about it.
 */
class CandidateQuery {
	public const PRE_LIMIT = 10000;
	/** Below this many matching files the strict pass is thin and the OR relaxation also runs. */
	public const THIN = 25;
	private const CFG = "'aps_search.spanish_unaccent'::regconfig";
	private const MAX_RELAX_TERMS = 12;

	public function __construct(private IndexDb $db) {
	}

	/**
	 * Keyword leg. $alternatives are extra plain phrases (glossary) ORed with the query; $relax: null = relax only when the
	 * strict pass is thin, true = only the OR form, false = strict only.
	 *
	 * @param list<string> $alternatives
	 * @return list<array{file_id: int, chunk_id: int}>
	 */
	public function exacta(string $query, array $alternatives, string $principalSet, Filters $filters, int $limit, ?bool $relax = null): array {
		$strict = [];
		if ($relax !== true) {
			$strict = $this->fts($this->strictTsquery($query, $alternatives), $principalSet, $filters, $limit);
		}
		if ($relax === false || ($relax === null && count($strict) >= self::THIN)) {
			return $strict;
		}
		$or = $this->relaxedTsquery($query, $alternatives);
		if ($or === null) {
			return $strict;
		}
		$relaxed = $this->fts($or, $principalSet, $filters, $limit);
		if ($strict === []) {
			return $relaxed;
		}
		return array_slice(Rrf::fuse([$strict, $relaxed], $limit), 0, $limit);        // strict matches keep their lead
	}

	/**
	 * Vector leg on generation $gen. $exactScan skips the HNSW index (small readable sets, or no index built yet).
	 *
	 * @param list<float> $vector
	 * @return list<array{file_id: int, chunk_id: int}>
	 */
	public function significado(array $vector, int $gen, string $principalSet, Filters $filters, int $limit, bool $exactScan): array {
		$dim = $this->dimOf($gen);
		if (count($vector) !== $dim) {
			throw new InvalidArgumentException('The query vector does not match the generation');
		}
		foreach ($vector as $x) {
			if (!is_float($x) && !is_int($x) || !is_finite((float)$x)) {
				throw new InvalidArgumentException('The query vector holds a value that is not finite');
			}
		}
		$literal = '[' . implode(',', array_map(static fn ($x): string => sprintf('%.8g', $x), $vector)) . ']';
		[$fsql, $fparams] = $filters->sql();
		$meta = $filters->isEmpty() ? '' : ' JOIN aps_search.file_meta m ON m.file_id = v.file_id';
		$order = $exactScan ? '(v.vec <=> ?::halfvec) + 0' : 'v.vec <=> ?::halfvec';          // "+ 0" keeps the planner off the index
		$sql = "WITH ann AS MATERIALIZED (
				SELECT v.chunk_id, v.file_id, (v.vec <=> ?::halfvec) AS d
				FROM aps_search.chunk_vec_$gen v JOIN aps_search.file_acl a ON a.file_id = v.file_id$meta
				WHERE a.principals && ?::text[]$fsql
				ORDER BY $order LIMIT ?)
			SELECT file_id, chunk_id FROM (
				SELECT DISTINCT ON (file_id) file_id, chunk_id, d FROM ann ORDER BY file_id, d + 0, chunk_id) best
			ORDER BY d, file_id LIMIT ?";
		$params = [$literal, $principalSet, ...$fparams, $literal, min(5000, max(1, $limit * 2)), $limit];
		return $this->db->guarded(function () use ($sql, $params, $exactScan): array {
			if (!$exactScan) {
				// Iterative scan keeps the index feeding a filtered query until enough rows pass (ADR-0001, S0 settings).
				$this->db->rows("SELECT set_config('hnsw.iterative_scan', 'relaxed_order', true), set_config('hnsw.max_scan_tuples', '20000', true), set_config('hnsw.ef_search', '200', true)");
			}
			return $this->shape($this->db->rows($sql, $params));
		});
	}

	/** Files the user could match at all, counted up to $cap + 1 (the exact-scan switch needs "fewer than N", not N). */
	public function accessibleFiles(string $principalSet, Filters $filters, int $cap): int {
		[$fsql, $fparams] = $filters->sql();
		$meta = $filters->isEmpty() ? '' : ' JOIN aps_search.file_meta m ON m.file_id = a.file_id';
		$rows = $this->db->rows(
			"SELECT count(*) AS n FROM (SELECT 1 FROM aps_search.file_acl a$meta WHERE a.principals && ?::text[]$fsql LIMIT ?) t",
			[$principalSet, ...$fparams, $cap + 1],
		);
		return (int)$rows[0]['n'];
	}

	public function hasAnnIndex(int $gen): bool {
		$this->dimOf($gen);
		return $this->db->rows(
			"SELECT 1 FROM pg_indexes WHERE schemaname = 'aps_search' AND tablename = ? AND indexdef ILIKE '%hnsw%'",
			['chunk_vec_' . $gen],
		) !== [];
	}

	public function buildIndex(int $gen): void {
		$this->dimOf($gen);
		$this->db->rows('SELECT aps_search.build_vec_index(?)', [$gen]);
	}

	private function dimOf(int $gen): int {
		$rows = $this->db->rows("SELECT dim FROM aps_search.embedding_profile WHERE gen = ? AND state <> 'retired'", [$gen]);
		if ($rows === []) {
			throw new InvalidArgumentException('Unknown embedding generation');
		}
		return (int)$rows[0]['dim'];
	}

	/** @param list<string> $alternatives @return array{0: string, 1: list<string>} */
	private function strictTsquery(string $query, array $alternatives): array {
		$sql = 'websearch_to_tsquery(' . self::CFG . ', ?)';
		foreach ($alternatives as $_) {
			$sql .= ' || websearch_to_tsquery(' . self::CFG . ', ?)';
		}
		return [$sql, [$query, ...$alternatives]];
	}

	/** @param list<string> $alternatives @return array{0: string, 1: list<string>}|null null when OR would equal the strict form */
	private function relaxedTsquery(string $query, array $alternatives): ?array {
		$terms = array_values(array_unique(array_filter(explode(' ', Glossary::fold($query)), static fn (string $t): bool => $t !== '')));
		if (count($terms) < 2) {
			return null;
		}
		$terms = array_slice($terms, 0, self::MAX_RELAX_TERMS);
		$sql = 'to_tsquery(' . self::CFG . ', ?)';
		foreach ($alternatives as $_) {
			$sql .= ' || websearch_to_tsquery(' . self::CFG . ', ?)';
		}
		return [$sql, [implode(' | ', $terms), ...$alternatives]];
	}

	/** @param array{0: string, 1: list<string>} $tsquery @return list<array{file_id: int, chunk_id: int}> */
	private function fts(array $tsquery, string $principalSet, Filters $filters, int $limit): array {
		[$qsql, $qparams] = $tsquery;
		[$fsql, $fparams] = $filters->sql();
		$meta = $filters->isEmpty() ? '' : ' JOIN aps_search.file_meta m ON m.file_id = c.file_id';
		$rows = $this->db->rows(
			"WITH q AS (SELECT $qsql AS tq),
			cand AS (
				SELECT c.chunk_id, c.file_id, c.tsv
				FROM aps_search.chunk c
				JOIN aps_search.file_acl a ON a.file_id = c.file_id$meta, q
				WHERE c.tsv @@ q.tq AND a.principals && ?::text[]$fsql
				ORDER BY c.chunk_id DESC
				LIMIT " . self::PRE_LIMIT . "
			),
			best AS (
				SELECT DISTINCT ON (cand.file_id) cand.file_id, cand.chunk_id, ts_rank(cand.tsv, q.tq) AS rank
				FROM cand, q
				ORDER BY cand.file_id, ts_rank(cand.tsv, q.tq) DESC, cand.chunk_id DESC
			)
			SELECT file_id, chunk_id FROM best ORDER BY rank DESC, file_id LIMIT ?",
			[...$qparams, $principalSet, ...$fparams, $limit],
		);
		return $this->shape($rows);
	}

	/** @param list<array<string, mixed>> $rows @return list<array{file_id: int, chunk_id: int}> */
	private function shape(array $rows): array {
		return array_map(static fn (array $r): array => ['file_id' => (int)$r['file_id'], 'chunk_id' => (int)$r['chunk_id']], $rows);
	}
}
