<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;

/**
 * Housekeeping for the vector lane, run by a background job: queue indexed files that still lack vectors in every live
 * generation (a batch per tick, nothing while paused), measure coverage, and mark a building generation ready once enough of
 * the corpus is embedded. It never activates a generation: that is an administrator's command (ADR-0004, ADR-0014).
 */
class VecBackfill {
	public function __construct(private IndexDb $db, private Settings $settings) {
	}

	/** @return array{queued: int, coverage: array<int, float>, ready: list<int>} */
	public function tick(int $batch): array {
		$paused = $this->settings->isPaused();
		$min = $this->minCoverage();
		$queued = 0;
		$coverage = [];
		$ready = [];
		foreach ($this->db->rows("SELECT gen, state FROM aps_search.embedding_profile WHERE state IN ('building', 'ready', 'active') ORDER BY gen") as $g) {
			$gen = (int)$g['gen'];
			if (!$paused) {
				$queued += (int)$this->db->rows('SELECT aps_search.enqueue_vec_backfill(?, ?) AS n', [$gen, $batch])[0]['n'];
			}
			$c = $this->db->rows('SELECT files, embedded FROM aps_search.vec_coverage(?)', [$gen])[0];
			$ratio = (int)$c['files'] === 0 ? 1.0 : (float)$c['embedded'] / (float)$c['files'];
			$coverage[$gen] = $ratio;
			$text = sprintf('%.4f', $ratio);
			$this->settings->set('vec_coverage_' . $gen, $text);
			if ($g['state'] === 'active') {
				$this->settings->set('vec_coverage', $text);
			}
			if ($g['state'] === 'building' && $ratio >= $min) {
				$this->db->rows('SELECT aps_search.mark_generation_ready(?)', [$gen]);
				$this->db->rows('SELECT aps_search.build_vec_index(?)', [$gen]);
				$ready[] = $gen;
			}
		}
		return ['queued' => $queued, 'coverage' => $coverage, 'ready' => $ready];
	}

	private function minCoverage(): float {
		$v = $this->settings->get('semantic_min_coverage');
		return $v !== null && is_numeric($v) && (float)$v > 0.0 && (float)$v <= 1.0 ? (float)$v : VectorState::DEFAULT_MIN_COVERAGE;
	}
}
