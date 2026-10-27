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
	/** Candidates ranked when every word is common and the reader has many files: the newest matches are dense, so fewer are enough, and finding them costs time in proportion (S7: 3.8 s at 10000 for a reader of a tenth of the index). */
	public const COMMON_PRE_LIMIT = 2000;
	/** A term in at least this share of all chunks is left out of the OR relaxation: it matches so much that it only adds noise and cost. */
	public const COMMON_SHARE = 0.02;
	/** Readers of fewer files than this get the plan that starts from their files, not from the matches of the words. */
	public const NARROW_FILES = 2000;
	private const CFG = "'aps_search.spanish_unaccent'::regconfig";
	private const MAX_RELAX_TERMS = 12;

	public function __construct(private IndexDb $db) {
	}

	/**
	 * Keyword leg. $pairs are the glossary's [folded query words, alternative]: the strict pass lets each alternative stand for
	 * its words, the OR relaxation adds it as one more term; $relax: null = relax only when the strict pass is thin, true = only
	 * the OR form, false = strict only.
	 *
	 * @param list<array{0: string, 1: string}> $pairs
	 * @return list<array{file_id: int, chunk_id: int}>
	 */
	public function exacta(string $query, array $pairs, string $principalSet, Filters $filters, int $limit, ?bool $relax = null): array {
		$strict = [];
		$words = $this->words($query);
		$common = $words === [] ? [] : $this->commonTerms($words);
		if ($relax !== true) {
			// A required word that is not common narrows the matches a lot: let the word index lead (see fts()).
			$strict = $this->fts($this->strictTsquery($query, $pairs), $principalSet, $filters, $limit, array_diff($words, $common) !== []);
		}
		if ($relax === false || ($relax === null && count($strict) >= self::THIN)) {
			return $strict;
		}
		$or = $this->relaxedTsquery($query, array_column($pairs, 1));
		if ($or === null) {
			return $strict;
		}
		$relaxed = $this->fts($or, $principalSet, $filters, $limit, array_diff($words, $common) !== []);
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
		[$fsql, $fparams] = $filters->sql('c');
		$meta = $filters->hasFileLevel() ? ' JOIN aps_search.file_meta m ON m.file_id = v.file_id' : '';
		$meta .= $filters->needsChunk() ? ' JOIN aps_search.chunk c ON c.chunk_id = v.chunk_id' : '';
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
				$this->db->rows("SELECT set_config('hnsw.iterative_scan', 'relaxed_order', true), set_config('hnsw.max_scan_tuples', '20000', true), set_config('hnsw.ef_search', ?, true)", [(string)$this->efSearch]);
			}
			return $this->shape($this->db->rows($sql, $params));
		});
	}

	/** Files the user could match at all, counted up to $cap + 1 (the exact-scan switch needs "fewer than N", not N). */
	public function accessibleFiles(string $principalSet, Filters $filters, int $cap): int {
		[$fsql, $fparams] = $filters->sql();                    // the chunk-level filter is left out: this is only a cheap count
		$meta = $filters->hasFileLevel() ? ' JOIN aps_search.file_meta m ON m.file_id = a.file_id' : '';
		$rows = $this->db->rows(
			"SELECT count(*) AS n FROM (SELECT 1 FROM aps_search.file_acl a$meta WHERE a.principals && ?::text[]$fsql LIMIT ?) t",
			[$principalSet, ...$fparams, $cap + 1],
		);
		return (int)$rows[0]['n'];
	}

	/**
	 * Of these files, the ones whose stored principals carry x:recheck: access the prefilter cannot model (circle, Talk, Deck,
	 * mail or federated shares, team folders with advanced ACL). Whether the caller may read them is only known to Nextcloud.
	 *
	 * @param list<int> $fileIds
	 * @return list<int>
	 */
	public function unmodelledAmong(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$rows = $this->db->rows(
			'SELECT file_id FROM aps_search.file_acl WHERE file_id = ANY(?::bigint[]) AND principals @> ARRAY[?]::text[]',
			[IndexDb::textArray(array_map('strval', $fileIds)), \OCA\BuscadorDocumental\Acl\PrincipalResolver::RECHECK],
		);
		return array_map(static fn (array $r): int => (int)$r['file_id'], $rows);
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

	/** @param list<array{0: string, 1: string}> $pairs @return array{0: string, 1: list<string>} */
	private function strictTsquery(string $query, array $pairs): array {
		$t = 'websearch_to_tsquery(' . self::CFG . ', ?)';
		$sql = $t;
		$params = [$query];
		$folded = Glossary::fold($query);
		// A shorter span rewritten first takes the words that a longer span containing it needs.
		usort($pairs, static fn (array $a, array $b): int => substr_count($b[0], ' ') <=> substr_count($a[0], ' '));
		foreach ($pairs as [$words, $alternative]) {
			if ($words === $folded) {
				// The alternative stands for the whole query ("REM", or a stopword key such as "LES" whose own tsquery is empty).
				$sql = "$sql || $t";
				$params[] = $alternative;
				continue;
			}
			// Swap the alternative in for its words only, inside the parsed query, so exclusions and phrases still apply to it.
			foreach (array_unique([$words, str_replace(' ', '-', $words)]) as $form) {     // a typed "CIE-10" parses as a phrase
				$sql = "ts_rewrite($sql, $t, $t || $t)";
				array_push($params, $form, $form, $alternative);
			}
		}
		return [$sql, $params];
	}

	/** @param list<string> $alternatives @return array{0: string, 1: list<string>}|null null when OR would equal the strict form */
	private function relaxedTsquery(string $query, array $alternatives): ?array {
		$terms = $this->words($query);
		if (count($terms) < 2) {
			return null;
		}
		$rare = array_values(array_diff($terms, $this->commonTerms($terms)));
		$terms = $rare === [] ? $terms : $rare;       // all common, or no statistics yet: all of them stay
		$sql = 'to_tsquery(' . self::CFG . ', ?)';
		foreach ($alternatives as $_) {
			$sql .= ' || websearch_to_tsquery(' . self::CFG . ', ?)';
		}
		return [$sql, [implode(' | ', $terms), ...$alternatives]];
	}

	/** @return list<string> the distinct folded words of the query, at most MAX_RELAX_TERMS */
	private function words(string $query): array {
		$terms = array_values(array_unique(array_filter(explode(' ', Glossary::fold($query)), static fn (string $t): bool => $t !== '')));
		return array_slice($terms, 0, self::MAX_RELAX_TERMS);
	}

	/**
	 * Which of these words are very common (in at least COMMON_SHARE of the chunks), by the planner's own statistics on the search
	 * column. Without statistics (a fresh database) none is: everything then behaves as before.
	 *
	 * @param list<string> $terms
	 * @return list<string>
	 */
	private function commonTerms(array $terms): array {
		$common = $this->db->rows(
			'SELECT t.term FROM unnest(?::text[]) AS t(term) WHERE EXISTS (
				SELECT 1 FROM pg_stats s, unnest(s.most_common_elems::text::text[], s.most_common_elem_freqs) AS u(e, fr)
				WHERE s.schemaname = \'aps_search\' AND s.tablename = \'chunk\' AND s.attname = \'tsv\' AND u.fr >= ?
				  AND u.e IN (SELECT unnest(tsvector_to_array(to_tsvector(' . self::CFG . ', t.term)))))',
			[IndexDb::textArray($terms), self::COMMON_SHARE],
		);
		return array_column($common, 'term');
	}

	/** hnsw.ef_search for the vector leg: more candidates per search, better recall, more time (S7 measured the trade). */
	public int $efSearch = 400;

	/** Tests set this to 0 to run the plan for readers of many files on a small corpus. */
	public int $narrowFiles = self::NARROW_FILES;

	/** @var array<string, bool> */
	private array $narrowMemo = [];

	private function isNarrow(string $principalSet, Filters $filters): bool {
		[$fsql, $fparams] = $filters->sql();
		$key = md5($principalSet . $fsql . serialize($fparams));
		return $this->narrowMemo[$key] ??= $this->accessibleFiles($principalSet, $filters, $this->narrowFiles) < $this->narrowFiles;
	}

	/** @param array{0: string, 1: list<string>} $tsquery @return list<array{file_id: int, chunk_id: int}> */
	private function fts(array $tsquery, string $principalSet, Filters $filters, int $limit, bool $wordsLead = false): array {
		[$qsql, $qparams] = $tsquery;
		[$fsql, $fparams] = $filters->sql('c');
		$meta = $filters->hasFileLevel() ? ' JOIN aps_search.file_meta m ON m.file_id = c.file_id' : '';
		// A reader of few files: start from those files and look for the words in their chunks (the planner otherwise walks the
		// newest chunks of the whole index looking for the few that this person may read). Everyone else: as before.
		$narrow = $this->isNarrow($principalSet, $filters);
		// The planner guesses every word it has no statistics for matches 0.5 % of the chunks and so walks the newest chunks of the
		// whole index hoping to find enough: for a rare word that is seconds. "+ 0" takes that index away from it, so the word
		// index finds the few matches first. Used when a required word is known not to be common, and for readers of few files
		// (who start from their files).
		$lead = $wordsLead || $narrow ? '+ 0' : '';
		$cap = $wordsLead || $narrow ? self::PRE_LIMIT : self::COMMON_PRE_LIMIT;
		// The query text is written into the places that use it, not kept in a CTE: with a literal the planner reads the word
		// statistics, with a CTE value it guesses and may choose to read every chunk of the index (measured: 7 s for a common word).
		if ($narrow) {
			$sql = "WITH acl AS MATERIALIZED (SELECT file_id FROM aps_search.file_acl WHERE principals && ?::text[]),
				cand AS MATERIALIZED (
					SELECT c.chunk_id, c.file_id, c.tsv FROM aps_search.chunk c JOIN acl a ON a.file_id = c.file_id$meta
					WHERE c.tsv @@ ($qsql)$fsql
					ORDER BY c.chunk_id $lead DESC LIMIT $cap
				),
				best AS (
					SELECT DISTINCT ON (file_id) file_id, chunk_id, ts_rank(tsv, ($qsql)) AS rank FROM cand
					ORDER BY file_id, rank DESC, chunk_id DESC
				)
				SELECT file_id, chunk_id FROM best ORDER BY rank DESC, file_id LIMIT ?";
			$params = [$principalSet, ...$qparams, ...$fparams, ...$qparams, $limit];
		} else {
			// Common words and a reader of many files: walk the newest chunks and test each match's file as a row filter. As a join
			// the planner (which thinks this reader has 500 files, not 5000) reads every chunk of those files first (measured 5 s).
			// Words that are not common are found through the word index, where the join is cheap.
			$join = $wordsLead ? ' JOIN aps_search.file_acl a ON a.file_id = c.file_id' : '';
			$access = $wordsLead
				? 'a.principals && ?::text[]'
				: '(SELECT a.principals FROM aps_search.file_acl a WHERE a.file_id = c.file_id) && ?::text[]';
			$sql = "WITH cand AS MATERIALIZED (
					SELECT c.chunk_id, c.file_id, c.tsv FROM aps_search.chunk c$join$meta
					WHERE c.tsv @@ ($qsql) AND $access$fsql
					ORDER BY c.chunk_id $lead DESC LIMIT $cap
				),
				best AS (
					SELECT DISTINCT ON (file_id) file_id, chunk_id, ts_rank(tsv, ($qsql)) AS rank FROM cand
					ORDER BY file_id, rank DESC, chunk_id DESC
				)
				SELECT file_id, chunk_id FROM best ORDER BY rank DESC, file_id LIMIT ?";
			$params = [...$qparams, $principalSet, ...$fparams, ...$qparams, $limit];
		}
		$rows = $this->db->rows($sql, $params);
		return $this->shape($rows);
	}

	/** @param list<array<string, mixed>> $rows @return list<array{file_id: int, chunk_id: int}> */
	private function shape(array $rows): array {
		return array_map(static fn (array $r): array => ['file_id' => (int)$r['file_id'], 'chunk_id' => (int)$r['chunk_id']], $rows);
	}
}
