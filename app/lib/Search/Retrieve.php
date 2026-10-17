<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCA\BuscadorDocumental\Db\IndexDb;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exacta search (S1): prefilter in SQL on stored principals, then the live Recheck decides, then snippets for the
 * final hits only. Relaxation, synonyms, filters, modes and paging are S4.
 *
 * Result: ['status' => 'ok'|'too_many'|'unavailable', 'message' => string, 'hits' => list<hit>] where a hit is
 *   ['file_id' => int, 'title' => string]                                   when download is disabled (title only, M2), or
 *   ['file_id' => int, 'title' => string, 'heading' => ?string, 'page_no' => ?int, 'snippet' => string]
 * Any failure returns no hits: there is no fallback to unrechecked data.
 */
class Retrieve {
	public const CANDIDATES = 10000;
	public const TIMEOUT_MS = 5000;
	public const MAX_LIMIT = 100;
	public const MAX_QUERY_CHARS = 500;
	private const MAX_ROUNDS = 5;

	private HitBuilder $builder;

	public function __construct(
		private IndexDb $db,
		private PrincipalResolver $principals,
		private Recheck $recheck,
		private LoggerInterface $logger,
	) {
		$this->builder = new HitBuilder($db);
	}

	/** @return array{status: string, message: string, hits: list<array<string, mixed>>} */
	public function run(string $uid, string $query, int $limit): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$query = mb_substr($query, 0, self::MAX_QUERY_CHARS);
		if (trim($query) === '') {
			return $this->result('ok', '', []);
		}
		try {
			$set = IndexDb::textArray($this->principals->forUser($uid));
			$survivors = [];
			$seen = [];
			for ($round = 0, $offset = 0; $round < self::MAX_ROUNDS && count($survivors) < $limit; $round++, $offset += $limit * 2) {
				$rows = $this->db->withTimeout(self::TIMEOUT_MS, fn (): array => $this->candidates($query, $set, $limit * 2, $offset));
				$fresh = array_values(array_filter($rows, static fn (array $r): bool => !isset($seen[(int)$r['file_id']])));
				foreach ($fresh as $r) {
					$seen[(int)$r['file_id']] = true;
				}
				$ok = $this->recheck->filter($uid, array_map(static fn (array $r): int => (int)$r['file_id'], $fresh));
				foreach ($fresh as $r) {
					if (isset($ok[(int)$r['file_id']]) && count($survivors) < $limit) {
						$survivors[] = [$r, $ok[(int)$r['file_id']]];
					}
				}
				if (count($rows) < $limit * 2) {
					break;
				}
			}
			return $this->result('ok', '', $this->hits($query, $survivors));
		} catch (ExclusionUnavailable $e) {
			$this->logger->warning('buscador_documental: exclusion state unavailable, search refused (' . get_class($e) . ')');      // not its message: A-SEC-5
			return $this->result('unavailable', 'La búsqueda no está disponible en este momento.', []);
		} catch (Throwable $e) {
			if (str_contains($e->getMessage(), 'statement timeout')) {
				return $this->result('too_many', 'Demasiados resultados: acote la búsqueda con más términos.', []);
			}
			$this->logger->error('buscador_documental: search failed (' . get_class($e) . ')');
			return $this->result('unavailable', 'La búsqueda no está disponible en este momento.', []);
		}
	}

	/** Best chunk per file among the newest CANDIDATES matches the principals can see, best rank first. */
	private function candidates(string $query, string $principalSet, int $take, int $offset): array {
		return $this->db->rows(
			"WITH q AS (SELECT websearch_to_tsquery('aps_search.spanish_unaccent'::regconfig, ?) AS tq),
			cand AS (
				SELECT c.chunk_id, c.file_id, c.tsv
				FROM aps_search.chunk c
				JOIN aps_search.file_acl a ON a.file_id = c.file_id, q
				WHERE c.tsv @@ q.tq AND a.principals && ?::text[]
				ORDER BY c.chunk_id DESC
				LIMIT " . self::CANDIDATES . "
			),
			best AS (
				SELECT DISTINCT ON (cand.file_id) cand.file_id, cand.chunk_id, ts_rank(cand.tsv, q.tq) AS rank
				FROM cand, q
				ORDER BY cand.file_id, ts_rank(cand.tsv, q.tq) DESC, cand.chunk_id DESC
			)
			SELECT best.file_id, best.chunk_id, fs.etag FROM best JOIN aps_search.file_state fs ON fs.file_id = best.file_id ORDER BY best.rank DESC, best.file_id LIMIT ? OFFSET ?",
			[$query, $principalSet, $take, $offset],
		);
	}

	/** @param list<array{0: array<string, mixed>, 1: RecheckResult}> $survivors @return list<array<string, mixed>> */
	private function hits(string $query, array $survivors): array {
		return $this->builder->build($query, [], $survivors);
	}

	/** @param list<array<string, mixed>> $hits */
	private function result(string $status, string $message, array $hits): array {
		return ['status' => $status, 'message' => $message, 'hits' => $hits];
	}
}
