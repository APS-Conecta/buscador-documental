<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Status;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;

/** What `occ buscador:status` shows: counts, ages and reason codes. Never a name, a path or any text. */
class StatusReport {
	public function __construct(private IndexDb $db) {
	}

	/**
	 * @return array{mode: string, paused: bool, notice_ack_at: ?string, jobs: array{queued: int, claimed: int},
	 *               states: array<string, int>, skipped: array<string, int>, outbox: array{pending: int, parked: int},
	 *               acl_backlog_seconds: ?int, last_reconcile: ?array{finished_at: string, counts: array},
	 *               vectors: array{generations: list<array{gen: int, profile: string, state: string, coverage: float}>, queued: int, failed: int}}
	 */
	public function snapshot(): array {
		$settings = new Settings($this->db);
		$jobs = $this->db->rows('SELECT
			count(*) FILTER (WHERE lease_until IS NULL OR lease_until <= clock_timestamp()) AS queued,
			count(*) FILTER (WHERE lease_until > clock_timestamp()) AS claimed FROM aps_search.job')[0];
		$outbox = $this->db->rows('SELECT
			count(*) FILTER (WHERE processed_at IS NULL) AS pending,
			count(*) FILTER (WHERE processed_at IS NULL AND attempts >= 5) AS parked FROM aps_search.outbox')[0];
		$backlog = $this->db->rows('SELECT extract(epoch FROM now() - min(acl_dirty_since)) AS s FROM aps_search.file_state WHERE acl_dirty')[0]['s'];
		$last = $this->db->rows('SELECT finished_at, counts FROM aps_search.reconcile_run WHERE finished_at IS NOT NULL ORDER BY run_id DESC LIMIT 1');
		return [
			'mode' => $settings->scopeMode(),
			'paused' => $settings->isPaused(),
			'notice_ack_at' => $settings->get('notice_ack_at'),
			'jobs' => ['queued' => (int)$jobs['queued'], 'claimed' => (int)$jobs['claimed']],
			'states' => $this->counts('SELECT text_state AS k, count(*) AS n FROM aps_search.file_state GROUP BY 1 ORDER BY 1'),
			'skipped' => $this->counts("SELECT skip_reason AS k, count(*) AS n FROM aps_search.file_state WHERE text_state = 'skipped' AND skip_reason IS NOT NULL GROUP BY 1 ORDER BY 1"),
			'outbox' => ['pending' => (int)$outbox['pending'], 'parked' => (int)$outbox['parked']],
			'acl_backlog_seconds' => $backlog === null ? null : (int)round((float)$backlog),
			'vectors' => $this->vectors($settings),
			'last_reconcile' => $last === [] ? null : ['finished_at' => (string)$last[0]['finished_at'], 'counts' => json_decode((string)$last[0]['counts'], true, 8, JSON_THROW_ON_ERROR)],
		];
	}

	/** @return array{generations: list<array{gen: int, profile: string, state: string, coverage: float}>, queued: int, failed: int} */
	private function vectors(Settings $settings): array {
		$gens = [];
		foreach ($this->db->rows("SELECT gen, profile_id, state FROM aps_search.embedding_profile WHERE state <> 'retired' ORDER BY gen") as $g) {
			$gens[] = ['gen' => (int)$g['gen'], 'profile' => (string)$g['profile_id'], 'state' => (string)$g['state'], 'coverage' => (float)($settings->get('vec_coverage_' . $g['gen']) ?? 0)];
		}
		$n = $this->db->rows("SELECT
			(SELECT count(*) FROM aps_search.job WHERE lane = 'vec') AS queued,
			(SELECT count(*) FROM aps_search.file_state WHERE vec_state = 'failed') AS failed")[0];
		return ['generations' => $gens, 'queued' => (int)$n['queued'], 'failed' => (int)$n['failed']];
	}

	/** @return array<string, int> */
	private function counts(string $sql): array {
		$out = [];
		foreach ($this->db->rows($sql) as $r) {
			$out[(string)$r['k']] = (int)$r['n'];
		}
		return $out;
	}
}
