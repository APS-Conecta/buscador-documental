<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Status;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;

/** What `occ buscador:status` shows: counts, ages and reason codes. Never a name, a path or any text. */
class StatusReport {
	public function __construct(private IndexDb $db, private ?\OCA\BuscadorDocumental\Ops\WorkWindow $window = null) {
	}

	/**
	 * @return array{mode: string, paused: bool, notice_ack_at: ?string, jobs: array{queued: int, claimed: int},
	 *               states: array<string, int>, skipped: array<string, int>, outbox: array{pending: int, parked: int},
	 *               acl_backlog_seconds: ?int, last_reconcile: ?array{finished_at: string, counts: array},
	 *               vectors: array{generations: list<array{gen: int, profile: string, state: string, coverage: float}>, queued: int, failed: int},
	 *               eta: array<string, array<string, mixed>>, workers: array<string, int>, schema_bytes: int, last_reconcile_age_seconds: ?int}
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
			'eta' => $this->eta($settings),
			'workers' => $this->workers(),
			'schema_bytes' => $this->schemaBytes(),
			'last_reconcile_age_seconds' => $last === [] ? null : (int)$this->db->rows('SELECT extract(epoch FROM now() - ?::timestamptz) AS s', [$last[0]['finished_at']])[0]['s'],
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

	/** @return array{text: array<string, mixed>, vec: array<string, mixed>} */
	private function eta(Settings $settings): array {
		$paused = $settings->isPaused();
		$now = new \DateTimeImmutable('now');
		$w = $this->window?->show($now);
		$opens = $this->window?->opensInSeconds($now);
		$closed = $w !== null && !$w['open_now'];
		$r = $this->db->rows("SELECT
			(SELECT count(*) FROM aps_search.job WHERE lane = 'text') AS text_jobs,
			(SELECT count(*) FROM aps_search.outbox o WHERE o.processed_at IS NULL AND o.kind = 'file' AND o.attempts < 5
				AND NOT EXISTS (SELECT 1 FROM aps_search.file_state fs WHERE fs.file_id = o.file_id AND fs.text_state = 'indexed')) AS text_events,
			(SELECT count(*) FROM aps_search.job WHERE lane = 'vec') AS vec_jobs,
			(SELECT count(*) FROM aps_search.file_state WHERE text_state = 'indexed') AS indexed,
			(SELECT count(*) FROM aps_search.file_state WHERE indexed_at > now() - interval '1 hour') AS t1,
			(SELECT count(*) FROM aps_search.file_state WHERE indexed_at > now() - interval '2 hours' AND indexed_at <= now() - interval '1 hour') AS t2,
			(SELECT count(*) FROM aps_search.file_state WHERE embedded_at > now() - interval '1 hour') AS v1,
			(SELECT count(*) FROM aps_search.file_state WHERE embedded_at > now() - interval '2 hours' AND embedded_at <= now() - interval '1 hour') AS v2,
			(SELECT max(gen) FROM aps_search.embedding_profile WHERE state <> 'retired') AS live_gen")[0];
		// The newest live generation is the one being worked on (a first build has none active yet).
		$coverage = $r['live_gen'] === null ? 1.0 : (float)($settings->get('vec_coverage_' . (int)$r['live_gen']) ?? 0);
		$vecRemaining = $r['live_gen'] !== null ? max((int)$r['vec_jobs'], (int)round((1 - $coverage) * (int)$r['indexed'])) : 0;
		$lanes = $w['lanes'] ?? 'vec';
		return [
			'text' => Eta::estimate((int)$r['text_jobs'] + (int)$r['text_events'], (int)$r['t1'], (int)$r['t2'], $paused, !($closed && $lanes === 'all'), $opens),
			'vec' => Eta::estimate($vecRemaining, (int)$r['v1'], (int)$r['v2'], $paused, !$closed, $opens),
		];
	}

	/** @return array<string, int> lane => seconds since a worker last asked for work */
	private function workers(): array {
		$out = [];
		foreach ($this->db->rows('SELECT lane, min(extract(epoch FROM now() - at)) AS age FROM aps_search.worker_seen GROUP BY lane ORDER BY lane') as $row) {
			$out[(string)$row['lane']] = (int)round((float)$row['age']);
		}
		return $out;
	}

	private function schemaBytes(): int {
		return (int)$this->db->rows("SELECT coalesce(sum(pg_total_relation_size(c.oid)), 0) AS b FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'aps_search' AND c.relkind = 'r'")[0]['b'];
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
