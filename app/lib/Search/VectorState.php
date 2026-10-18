<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;

/** What the search side needs to know about the vector index: the active profile, whether the semantic modes may be used, tunables. */
class VectorState {
	public const DEFAULT_MIN_COVERAGE = 0.95;

	public function __construct(private IndexDb $db, private Settings $settings) {
	}

	/** @return array{gen: int, model: string, digest: string, dim: int, query_prefix: string, max_tokens: int}|null */
	public function activeProfile(): ?array {
		$rows = $this->db->rows("SELECT gen, model, digest, dim, query_prefix, max_tokens FROM aps_search.embedding_profile WHERE state = 'active'");
		if ($rows === []) {
			return null;
		}
		$r = $rows[0];
		return ['gen' => (int)$r['gen'], 'model' => (string)$r['model'], 'digest' => (string)$r['digest'], 'dim' => (int)$r['dim'],
			'query_prefix' => (string)$r['query_prefix'], 'max_tokens' => (int)$r['max_tokens']];
	}

	/**
	 * The semantic modes are offered only with an active profile, after an administrator validated hybrid on the gold set
	 * (`semantic_enabled`, A-QUAL-4) and with enough of the corpus embedded (`vec_coverage`, kept by housekeeping).
	 *
	 * @return array{semantic: bool, reason: ?string, coverage: float, gen: ?int}
	 */
	public function availability(): array {
		$profile = $this->activeProfile();
		$coverage = $this->float('vec_coverage', 0.0);
		if ($profile === null) {
			return ['semantic' => false, 'reason' => 'no_profile', 'coverage' => 0.0, 'gen' => null];
		}
		$out = ['semantic' => false, 'reason' => null, 'coverage' => $coverage, 'gen' => $profile['gen']];
		if ($this->settings->get('semantic_enabled') !== '1') {
			$out['reason'] = 'not_validated';
		} elseif ($coverage < $this->float('semantic_min_coverage', self::DEFAULT_MIN_COVERAGE)) {
			$out['reason'] = 'coverage';
		} else {
			$out['semantic'] = true;
		}
		return $out;
	}

	public function exactScanFiles(): int {
		return $this->positiveInt('exact_scan_files', 2000);
	}

	public function embedTimeoutMs(): int {
		return $this->positiveInt('embed_timeout_ms', 1500);
	}

	private function positiveInt(string $key, int $default): int {
		$v = $this->settings->get($key);
		return $v !== null && ctype_digit($v) && (int)$v > 0 ? (int)$v : $default;
	}

	private function float(string $key, float $default): float {
		$v = $this->settings->get($key);
		return $v !== null && is_numeric($v) && (float)$v >= 0.0 && (float)$v <= 1.0 ? (float)$v : $default;
	}
}
