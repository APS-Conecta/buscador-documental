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
	 * @param bool $details add path, mtime, owner and the uncertain-reading flag (the search page; the keyword provider keeps the short shape)
	 * @return list<array<string, mixed>>
	 */
	public function build(string $query, array $alternatives, array $survivors, bool $details = false, float $uncertainBelow = 60.0): array {
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
				"SELECT chunk_id, file_id, page_no, heading,
					ts_headline('aps_search.spanish_unaccent'::regconfig, text, $tq,
						'StartSel=" . $open . ", StopSel=" . $close . ", MaxWords=35, MinWords=15, MaxFragments=1') AS snippet
				 FROM aps_search.chunk WHERE chunk_id = ANY(?::bigint[])",
				[$query, ...$alternatives, '{' . implode(',', $ids) . '}'],
			));
			foreach ($rows as $row) {
				$chunks[(int)$row['chunk_id']] = $row;
			}
		}

		$conf = [];
		if ($details && $survivors !== []) {
			$all = array_map(static fn (array $s): int => (int)$s[0]['chunk_id'], $survivors);
			foreach ($this->db->rows(
				'SELECT c.chunk_id, pg.ocr_conf FROM aps_search.chunk c LEFT JOIN aps_search.page pg ON pg.file_id = c.file_id AND pg.page_no = c.page_no WHERE c.chunk_id = ANY(?::bigint[])',
				['{' . implode(',', $all) . '}'],
			) as $row) {
				$conf[(int)$row['chunk_id']] = $row['ocr_conf'] === null ? null : (float)$row['ocr_conf'];
			}
		}
		$hits = [];
		foreach ($survivors as [$row, $r]) {
			$hit = ['file_id' => $r->fileId, 'title' => $r->node->getName()];
			if ($details) {
				// All of it comes from the node the caller has just been allowed to read, plus the page's own confidence.
				$hit['path'] = self::relativePath($r->node);
				$hit['mtime'] = (int)$r->node->getMTime();
				$hit['owner'] = self::ownerName($r->node);
				$c = $conf[(int)$row['chunk_id']] ?? null;
				$hit['uncertain'] = $c !== null && $c < $uncertainBelow;
			}
			$chunk = $chunks[(int)$row['chunk_id']] ?? null;
			// the chunk must belong to this very file (a defence in depth: the snapshot is private and ids are not guessable)
			if ($this->snippetServable($row, $r) && $chunk !== null && (int)($chunk['file_id'] ?? $r->fileId) === $r->fileId) {
				$hit['heading'] = $chunk['heading'] === null ? null : (string)$chunk['heading'];
				$hit['page_no'] = $chunk['page_no'] === null ? null : (int)$chunk['page_no'];
				$hit['snippet'] = str_replace([$open, $close], ['<mark>', '</mark>'], htmlspecialchars((string)$chunk['snippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
			}
			$hits[] = $hit;
		}
		return $hits;
	}

	private static function relativePath(\OCP\Files\Node $node): string {
		return preg_match('#^/[^/]+/files(/.*)$#D', (string)$node->getPath(), $m) === 1 ? $m[1] : '/' . $node->getName();
	}

	private static function ownerName(\OCP\Files\Node $node): ?string {
		try {
			return $node->getOwner()?->getDisplayName();
		} catch (\Throwable) {
			return null;                                          // a team folder has no owner
		}
	}

	/** A snippet comes from the indexed text; once the file changed (etag differs) it may no longer be the file's content. */
	private function snippetServable(array $row, RecheckResult $r): bool {
		return $r->snippetAllowed && (string)($row['etag'] ?? '') === (string)$r->node->getEtag();
	}
}
