<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * Turns rechecked survivors into the hits the API returns: title for everyone, snippet only where download is allowed and
 * the indexed text still belongs to the file (same etag). A chunk found by meaning that shares no word with the query gets the
 * start of its text, which is what ts_headline returns when nothing matches.
 */
class HitBuilder {
	public const TIMEOUT_MS = 5000;

	public function __construct(private IndexDb $db) {
	}

	/**
	 * @param list<string> $alternatives extra plain phrases ORed with the query for highlighting
	 * @param list<array{0: array<string, mixed>, 1: RecheckResult}> $survivors rows carry chunk_id and the indexed etag
	 * @return list<array<string, mixed>>
	 */
	public function build(string $query, array $alternatives, array $survivors): array {
		$ids = [];
		foreach ($survivors as [$row, $r]) {
			if ($this->snippetServable($row, $r)) {
				$ids[] = (int)$row['chunk_id'];
			}
		}
		$mark = bin2hex(random_bytes(8)); // a document cannot forge these markers
		$open = "[[$mark:b]]";
		$close = "[[$mark:e]]";
		$chunks = [];
		if ($ids !== []) {
			$tq = 'websearch_to_tsquery(\'aps_search.spanish_unaccent\'::regconfig, ?)';
			foreach ($alternatives as $_) {
				$tq .= ' || websearch_to_tsquery(\'aps_search.spanish_unaccent\'::regconfig, ?)';
			}
			$rows = $this->db->withTimeout(self::TIMEOUT_MS, fn (): array => $this->db->rows(
				"SELECT chunk_id, page_no, heading,
					ts_headline('aps_search.spanish_unaccent'::regconfig, text, $tq,
						'StartSel=" . $open . ", StopSel=" . $close . ", MaxWords=35, MinWords=15, MaxFragments=1') AS snippet
				 FROM aps_search.chunk WHERE chunk_id = ANY(?::bigint[])",
				[$query, ...$alternatives, '{' . implode(',', $ids) . '}'],
			));
			foreach ($rows as $row) {
				$chunks[(int)$row['chunk_id']] = $row;
			}
		}

		$hits = [];
		foreach ($survivors as [$row, $r]) {
			$hit = ['file_id' => $r->fileId, 'title' => $r->node->getName()];
			$chunk = $chunks[(int)$row['chunk_id']] ?? null;
			if ($this->snippetServable($row, $r) && $chunk !== null) {
				$hit['heading'] = $chunk['heading'] === null ? null : (string)$chunk['heading'];
				$hit['page_no'] = $chunk['page_no'] === null ? null : (int)$chunk['page_no'];
				$hit['snippet'] = str_replace([$open, $close], ['<mark>', '</mark>'], htmlspecialchars((string)$chunk['snippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
			}
			$hits[] = $hit;
		}
		return $hits;
	}

	/** A snippet comes from the indexed text; once the file changed (etag differs) it may no longer be the file's content. */
	private function snippetServable(array $row, RecheckResult $r): bool {
		return $r->snippetAllowed && (string)($row['etag'] ?? '') === (string)$r->node->getEtag();
	}
}
