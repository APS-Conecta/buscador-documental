<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ui;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\Recheck;
use OCA\BuscadorDocumental\Status\Reindexer;

/** What the administration page can do. Every action is audited with the acting administrator (pseudonymised by the audit log). */
class AdminActions {
	/** Skip reasons that are a problem to look at; the policy ones (excluded, out of scope, trashed, user deleted) are not. */
	public const PROBLEM_REASONS = ['encrypted', 'too_large', 'corrupt', 'unsupported', 'ocr_failed', 'timeout', 'internal', 'unsafe'];

	public function __construct(
		private IndexDb $db,
		private Settings $settings,
		private ScopeRules $rules,
		private AuditLog $audit,
		private Reindexer $reindexer,
		private Recheck $recheck,
	) {
	}

	public function pause(string $actorUid): void {
		$this->settings->setPaused(true);
		$this->audit->record($actorUid, 'pause', null, ['paused' => true]);
	}

	public function resume(string $actorUid): void {
		$this->settings->setPaused(false);
		$this->audit->record($actorUid, 'resume', null, ['paused' => false]);
	}

	public function acknowledgeNotice(string $actorUid): void {
		$this->settings->set('notice_ack_at', gmdate('Y-m-d\TH:i:s\Z'));
		$this->audit->record($actorUid, 'notice.ack', null, []);
	}

	public function excludeRule(int $ruleId, string $actorUid): bool {
		return $this->rules->remove($ruleId, $actorUid);
	}

	public function retry(int $fileId, string $actorUid): bool {
		$ok = $this->reindexer->retry($fileId);
		if ($ok) {
			$this->audit->record($actorUid, 'reconcile', null, ['file_id' => $fileId]);
		}
		return $ok;
	}

	/**
	 * Files the worker gave up on, by reason. A name is shown only for files the administrator could open themselves: being an
	 * administrator does not make other people's file names visible here.
	 *
	 * @return array{counts: array<string, int>, items: list<array{file_id: int, reason: string, name: ?string}>}
	 */
	public function skipped(?string $reason, int $offset, int $limit, string $uid = 'admin'): array {
		$counts = [];
		foreach ($this->db->rows("SELECT skip_reason AS k, count(*) AS n FROM aps_search.file_state WHERE text_state = 'skipped' AND skip_reason = ANY(?::text[]) GROUP BY 1 ORDER BY n DESC, k", [IndexDb::textArray(self::PROBLEM_REASONS)]) as $r) {
			$counts[(string)$r['k']] = (int)$r['n'];
		}
		if ($reason !== null && !in_array($reason, self::PROBLEM_REASONS, true)) {
			return ['counts' => $counts, 'items' => []];
		}
		$reasons = $reason === null ? self::PROBLEM_REASONS : [$reason];
		$rows = $this->db->rows(
			"SELECT file_id, skip_reason FROM aps_search.file_state WHERE text_state = 'skipped' AND skip_reason = ANY(?::text[]) ORDER BY file_id LIMIT ? OFFSET ?",
			[IndexDb::textArray($reasons), max(1, min(100, $limit)), max(0, $offset)],
		);
		$ids = array_map(static fn (array $r): int => (int)$r['file_id'], $rows);
		$readable = $ids === [] ? [] : $this->recheck->filter($uid, $ids);
		$items = [];
		foreach ($rows as $r) {
			$id = (int)$r['file_id'];
			$items[] = ['file_id' => $id, 'reason' => (string)$r['skip_reason'], 'name' => isset($readable[$id]) ? $readable[$id]->node->getName() : null];
		}
		return ['counts' => $counts, 'items' => $items];
	}
}
