<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

/** Reciprocal rank fusion at file level (k = 60). Only ranks matter, so no score needs normalising. */
final class Rrf {
	public const K = 60;

	/**
	 * @param list<list<array{file_id: int, chunk_id: int}>> $legs each best first; earlier legs win ties for the shown chunk
	 * @return list<array{file_id: int, chunk_id: int, score: float}> best first, at most $limit
	 */
	public static function fuse(array $legs, int $limit): array {
		$score = [];
		$chunk = [];
		$bestRank = [];
		foreach ($legs as $leg) {
			$seen = [];
			foreach ($leg as $i => $row) {
				$f = (int)$row['file_id'];
				if (isset($seen[$f])) {
					continue;                           // a leg already gives one chunk per file; a repeat never scores twice
				}
				$seen[$f] = true;
				$rank = $i + 1;
				$score[$f] = ($score[$f] ?? 0.0) + 1.0 / (self::K + $rank);
				if (!isset($bestRank[$f]) || $rank < $bestRank[$f]) {
					$bestRank[$f] = $rank;
					$chunk[$f] = (int)$row['chunk_id'];
				}
			}
		}
		$ids = array_keys($score);
		usort($ids, static fn (int $a, int $b): int => [round($score[$b], 12), $a] <=> [round($score[$a], 12), $b]);
		$out = [];
		foreach (array_slice($ids, 0, max(0, $limit)) as $f) {
			$out[] = ['file_id' => $f, 'chunk_id' => $chunk[$f], 'score' => $score[$f]];
		}
		return $out;
	}
}
