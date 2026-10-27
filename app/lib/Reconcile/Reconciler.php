<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Reconcile;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\Outbox;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\ScopeSql;
use OCA\BuscadorDocumental\Scope\Settings;

/**
 * Nightly reconciliation (A-OPS-3, ADR-0013). Repairs whatever events missed, without ever looping per file: a run
 * materialises the files that SHOULD be indexed (scope minus the exclusion tag's subtrees) once, then walks keyset
 * windows of file ids.
 *
 *  eligible  -> reconcile_node: scope rules, minus everything under a file tagged rag-excluded
 *  reverse   -> outbox rows for known files that are gone from Nextcloud or are no longer eligible (removals go first)
 *  forward   -> outbox rows for files that should be indexed but are missing, stale, moved storage, or were erased for a
 *               reason that no longer holds (additions; only these count towards the per-day cap)
 *  acl       -> weekly: every indexed file's principals are recomputed (marked dirty; the processor refreshes them)
 *  house     -> tombstones past their date, audit retention, processed outbox rows, old runs
 *
 * It never erases directly: it queues, and the processor (one code path) decides and labels. A run resumes from its
 * cursor, is abandoned after 36 h or when the scope rules change, and stops queueing ADDITIONS past a per-day cap (backfill
 * and its throttle belong to S5). Removals are never held back by the cap or by a pause.
 */
class Reconciler {
	public const STALE_HOURS = 36;
	public const DEFAULT_CAP = 50000;
	public const ACL_EVERY_DAYS = 7;
	private const REASONS = "'excluded', 'out_of_scope', 'trashed', 'user_deleted'";

	public function __construct(private IndexDb $db, private ScopeRules $rules, private int $window = 5000, private ?\OCP\IUserManager $users = null) {
	}

	/** Inside the window, and either a run is open or none finished on this local date. */
	public function due(\DateTimeImmutable $now, string $window): bool {
		if ((new Settings($this->db))->get('reconcile_now') === '1') {
			return true;                                  // the scope rules or the mode changed: do not wait for tonight
		}
		if (!ReconcileWindow::contains($window, $now)) {
			return false;
		}
		if ($this->db->rows('SELECT 1 FROM aps_search.reconcile_run WHERE finished_at IS NULL LIMIT 1') !== []) {
			return true;
		}
		$last = $this->db->rows('SELECT finished_at FROM aps_search.reconcile_run WHERE finished_at IS NOT NULL ORDER BY finished_at DESC LIMIT 1');
		if ($last === []) {
			return true;
		}
		$finished = (new \DateTimeImmutable((string)$last[0]['finished_at']))->setTimezone($now->getTimezone());
		return $finished->format('Y-m-d') !== $now->format('Y-m-d');
	}

	/**
	 * Advances the current run (starting one if none is open) for up to $budgetSeconds, at least one step.
	 *
	 * @return array{finished: bool, suspended?: bool, phase: string, counts: array<string, mixed>}
	 */
	public function runOnce(int $budgetSeconds, string $today): array {
		$start = microtime(true);
		$run = $this->openRun();
		do {
			$counts = $run['counts'];
			if (($counts['day'] ?? null) !== $today) {
				$counts['day'] = $today;
				$counts['queued_today'] = 0;
			}
			if ($run['phase'] === 'forward' && (int)($counts['queued_today'] ?? 0) >= $this->cap()) {
				$counts += $this->houseIfDue();               // a long backfill must not keep the ledger and the audit from being pruned
				$this->save($run['id'], $run['phase'], $run['cursor'], $counts);
				return ['finished' => false, 'suspended' => true, 'phase' => $run['phase'], 'counts' => $counts];
			}
			[$phase, $cursor, $counts] = $this->step($run['id'], $run['phase'], $run['cursor'], $counts);
			$run = ['id' => $run['id'], 'phase' => $phase, 'cursor' => $cursor, 'counts' => $counts];
			if ($phase === 'done') {
				$this->db->execute('UPDATE aps_search.reconcile_run SET phase = ?, cursor_file_id = ?, counts = ?::jsonb, finished_at = now() WHERE run_id = ?',
					[$phase, $cursor, json_encode($counts), $run['id']]);
				$this->db->execute('DELETE FROM aps_search.reconcile_node WHERE run_id = ?', [$run['id']]);
				$this->db->execute("DELETE FROM aps_search.settings WHERE key = 'reconcile_now'");       // a full evaluation has now seen the changed rules
				return ['finished' => true, 'phase' => $phase, 'counts' => $counts];
			}
			$this->save($run['id'], $phase, $cursor, $counts);
		} while (microtime(true) - $start < $budgetSeconds);
		return ['finished' => false, 'phase' => $run['phase'], 'counts' => $run['counts']];
	}

	/**
	 * The statements of one window of each pass, for EXPLAIN in the scale benchmark: the benchmark must explain the SQL
	 * that really runs, not a copy of it.
	 *
	 * @return array<string, array{0: string, 1: list<mixed>}> name => [sql, params]
	 */
	public function statements(int $run, int $from, int $to): array {
		[$eligible, $params] = ScopeSql::eligible($this->rules->current());
		return [
			'materialise' => [$this->materialiseSql($eligible), [$run, ...$params]],
			'forward' => [$this->forwardSql(), [$run, $from, $to]],
			'reverse' => [$this->reverseSql(), [$run, $from, $to]],
			'acl' => [$this->aclSql(), [$from, $to]],
		];
	}

	/** @return array{id: int, phase: string, cursor: int, counts: array<string, mixed>} */
	private function openRun(): array {
		$fp = $this->rules->fingerprint();
		$rows = $this->db->rows('SELECT run_id, phase, cursor_file_id, rules_fp, counts, started_at < now() - make_interval(hours => ?) AS stale
			FROM aps_search.reconcile_run WHERE finished_at IS NULL ORDER BY run_id DESC LIMIT 1', [self::STALE_HOURS]);
		if ($rows !== []) {
			$r = $rows[0];
			$counts = json_decode((string)$r['counts'], true) ?: [];
			if (!$r['stale'] && $r['rules_fp'] === $fp) {
				return ['id' => (int)$r['run_id'], 'phase' => (string)$r['phase'], 'cursor' => (int)$r['cursor_file_id'], 'counts' => $counts];
			}
			$counts[$r['stale'] ? 'abandoned' : 'restarted'] = 1;               // too old, or the scope changed under it
			$this->db->execute('UPDATE aps_search.reconcile_run SET finished_at = now(), counts = ?::jsonb WHERE run_id = ?', [json_encode($counts), (int)$r['run_id']]);
			$this->db->execute('DELETE FROM aps_search.reconcile_node WHERE run_id = ?', [(int)$r['run_id']]);
		}
		$id = (int)$this->db->rows("INSERT INTO aps_search.reconcile_run (phase, rules_fp, counts) VALUES ('eligible', ?, '{}') RETURNING run_id", [$fp])[0]['run_id'];
		return ['id' => $id, 'phase' => 'eligible', 'cursor' => 0, 'counts' => ['eligible' => 0, 'forward' => 0, 'reverse' => 0, 'acl_marked' => 0]];
	}

	private function save(int $id, string $phase, int $cursor, array $counts): void {
		$this->db->execute('UPDATE aps_search.reconcile_run SET phase = ?, cursor_file_id = ?, counts = ?::jsonb WHERE run_id = ?', [$phase, $cursor, json_encode($counts), $id]);
	}

	/** @return array{0: string, 1: int, 2: array<string, mixed>} */
	private function step(int $run, string $phase, int $cursor, array $counts): array {
		switch ($phase) {
			case 'eligible':
				$counts['eligible'] = $this->materialise($run);
				return ['reverse', 0, $counts];
			case 'reverse':
				return $this->window($phase, $cursor, $counts, 'forward',
					'SELECT max(file_id) AS up FROM (SELECT file_id FROM aps_search.file_state WHERE file_id > ? ORDER BY file_id LIMIT ?) w',
					[$cursor, $this->window], $this->reverseSql(), 'reverse', $run);
			case 'forward':
				return $this->window($phase, $cursor, $counts, $this->aclDue() ? 'acl' : 'house',
					'SELECT max(file_id) AS up FROM (SELECT file_id FROM aps_search.reconcile_node WHERE run_id = ? AND file_id > ? ORDER BY file_id LIMIT ?) w',
					[$run, $cursor, $this->window], $this->forwardSql(), 'forward', $run);
			case 'acl':
				[$next, $cur, $counts] = $this->window($phase, $cursor, $counts, 'house',
					"SELECT max(file_id) AS up FROM (SELECT file_id FROM aps_search.file_state WHERE text_state = 'indexed' AND file_id > ? ORDER BY file_id LIMIT ?) w",
					[$cursor, $this->window], $this->aclSql(), 'acl_marked', $run);
				if ($next === 'house') {
					(new Settings($this->db))->set('last_acl_full_at', gmdate('Y-m-d\TH:i:s\Z'));
				}
				return [$next, $cur, $counts];
			case 'house':
				$counts += $this->housekeeping();
				return ['done', 0, $counts];
		}
		return ['done', 0, $counts];
	}

	/**
	 * One keyset window: find the upper bound of the next $this->window ids, run the pass over (cursor, upper].
	 *
	 * @return array{0: string, 1: int, 2: array<string, mixed>}
	 */
	private function window(string $phase, int $cursor, array $counts, string $next, string $boundSql, array $boundParams, string $passSql, string $counter, int $run): array {
		$up = $this->db->rows($boundSql, $boundParams)[0]['up'];
		if ($up === null) {
			return [$next, 0, $counts];
		}
		$params = $phase === 'forward' ? [$run, $cursor, (int)$up] : ($phase === 'reverse' ? [$run, $cursor, (int)$up] : [$cursor, (int)$up]);
		$n = $this->db->execute($passSql, $params);
		$counts[$counter] = (int)($counts[$counter] ?? 0) + $n;
		if ($phase === 'forward') {
			$counts['queued_today'] = (int)($counts['queued_today'] ?? 0) + $n;
		}
		return [$phase, (int)$up, $counts];
	}

	/** The eligible set into reconcile_node: scope rules minus every file at or below a tagged node. */
	private function materialise(int $run): int {
		[$eligible, $params] = ScopeSql::eligible($this->rules->current());
		return $this->db->execute($this->materialiseSql($eligible), [$run, ...$params]);
	}

	private function materialiseSql(string $eligible): string {
		return "WITH RECURSIVE excl AS (
				SELECT m.objectid::bigint AS fileid FROM *PREFIX*systemtag_object_mapping m
				JOIN *PREFIX*systemtag t ON t.id = m.systemtagid
				WHERE lower(t.name) = '" . ExclusionChecker::TAG_NAME . "' AND m.objecttype = 'files' AND m.objectid ~ '^[0-9]+\$'
				UNION
				SELECT c.fileid FROM *PREFIX*filecache c JOIN excl p ON c.parent = p.fileid WHERE c.parent > 0
			)
			INSERT INTO aps_search.reconcile_node (run_id, file_id)
			SELECT ?::bigint, e.file_id FROM (" . $eligible . ') e WHERE NOT EXISTS (SELECT 1 FROM excl x WHERE x.fileid = e.file_id) ON CONFLICT DO NOTHING';
	}

	private function forwardSql(): string {
		return "INSERT INTO aps_search.outbox (file_id, kind)
			SELECT n.file_id, 'file' FROM aps_search.reconcile_node n
			JOIN *PREFIX*filecache f ON f.fileid = n.file_id
			LEFT JOIN aps_search.file_state fs ON fs.file_id = n.file_id
			LEFT JOIN aps_search.file_meta fm ON fm.file_id = n.file_id
			WHERE n.run_id = ? AND n.file_id > ? AND n.file_id <= ?
			  AND (fs.file_id IS NULL
			       OR fs.skip_reason IN (" . self::REASONS . ")
			       OR fs.etag IS DISTINCT FROM f.etag
			       OR fm.storage_id IS DISTINCT FROM f.storage)
			ON CONFLICT DO NOTHING";
	}

	private function reverseSql(): string {
		return "INSERT INTO aps_search.outbox (file_id, kind)
			SELECT fs.file_id, 'file' FROM aps_search.file_state fs
			LEFT JOIN *PREFIX*filecache f ON f.fileid = fs.file_id
			LEFT JOIN aps_search.reconcile_node n ON n.run_id = ? AND n.file_id = fs.file_id
			WHERE fs.file_id > ? AND fs.file_id <= ?
			  AND (f.fileid IS NULL
			       OR (n.file_id IS NULL AND (fs.text_state <> 'skipped' OR fs.skip_reason IS NULL OR fs.skip_reason NOT IN (" . self::REASONS . '))))
			ON CONFLICT DO NOTHING';
	}

	private function aclSql(): string {
		return "UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = coalesce(acl_dirty_since, now())
			WHERE text_state = 'indexed' AND file_id > ? AND file_id <= ?";
	}

	private function aclDue(): bool {
		$last = (new Settings($this->db))->get('last_acl_full_at');
		return $last === null || strtotime($last) < time() - self::ACL_EVERY_DAYS * 86400;
	}

	private function cap(): int {
		$v = (new Settings($this->db))->get('reconcile_cap');
		return $v === null ? self::DEFAULT_CAP : max(1, (int)$v);
	}

	/** Rows indexed before S3 carry no storage id; learn it from the file cache, a batch a night. */
	private function backfillStorage(): int {
		return $this->db->execute(
			'UPDATE aps_search.file_meta fm SET storage_id = f.storage FROM *PREFIX*filecache f
			 WHERE f.fileid = fm.file_id AND fm.storage_id IS NULL
			   AND fm.file_id IN (SELECT file_id FROM aps_search.file_meta WHERE storage_id IS NULL ORDER BY file_id LIMIT 50000)',
		);
	}

	/** A uid mapped to an audit token must disappear with its user, also when the erasure step itself failed. */
	private function forgetVanishedUsers(): int {
		if ($this->users === null) {
			return 0;
		}
		$n = 0;
		foreach ($this->db->rows('SELECT uid FROM aps_search.pseudonym') as $r) {
			$uid = (string)$r['uid'];
			if (!in_array($uid, Pseudonyms::NON_PEOPLE, true) && !$this->users->userExists($uid)) {
				(new Pseudonyms($this->db))->forget($uid);
				$n++;
			}
		}
		return $n;
	}

	/** Housekeeping at most once a day outside the normal end of a run. @return array<string, int> */
	private function houseIfDue(): array {
		$last = (new Settings($this->db))->get('last_house_at');
		if ($last !== null && strtotime($last) > time() - 20 * 3600) {
			return [];
		}
		return $this->housekeeping();
	}

	/** @return array<string, int> */
	private function housekeeping(): array {
		$settings = new Settings($this->db);
		$settings->set('last_house_at', gmdate('Y-m-d\TH:i:s\Z'));
		$days = (int)($settings->get('audit_retention_days') ?? 90);
		$out = [
			'tombstones_purged' => $this->db->execute('DELETE FROM aps_search.tombstone WHERE purge_after IS NOT NULL AND purge_after < now()'),
			'audit_pruned' => $this->db->execute('DELETE FROM aps_search.audit_log WHERE at < now() - make_interval(days => ?)', [$days]),
			'outbox_pruned' => (new Outbox($this->db))->prune(),
			'storage_backfilled' => $this->backfillStorage(),
			'pseudonyms_forgotten' => $this->forgetVanishedUsers(),
		];
		$this->db->execute('DELETE FROM aps_search.reconcile_run WHERE finished_at IS NOT NULL AND run_id NOT IN (SELECT run_id FROM aps_search.reconcile_run ORDER BY run_id DESC LIMIT 30)');
		return $out;
	}
}
