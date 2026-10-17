<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\FileTree;
use OCA\BuscadorDocumental\Scope\ScopePredicate;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use Psr\Log\LoggerInterface;

/**
 * Re-evaluates one file from the truth (file cache, tags, scope rules), never from the event that pointed at it
 * (ADR-0013). Removals come first and are never held back; additions wait for a pause to end.
 *
 * Outcomes: purged, erased_trashed, erased_excluded, erased_out_of_scope, ignored, held, retry, enqueued, acl_written,
 * expanded, acl_marked, rule.
 */
class Processor {
	public const TRASH_LEDGER_DAYS = 30;

	private Outbox $outbox;

	public function __construct(
		private IndexDb $db,
		private FileTree $tree,
		private ScopeRules $rules,
		private ScopePredicate $predicate,
		private ExclusionChecker $exclusion,
		private FileIndexer $indexer,
		private PrincipalWriter $acl,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
		$this->outbox = new Outbox($db);
	}

	public function process(int $fileId, string $kind): string {
		if ($kind === 'rule') {
			// The exclusion tag was deleted: whatever it had excluded must be looked at again.
			$this->db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT file_id, 'file' FROM aps_search.file_state WHERE skip_reason = 'excluded' ON CONFLICT DO NOTHING");
			return 'rule';
		}
		$node = $this->tree->node($fileId);
		if ($node === null) {
			$this->db->rows('SELECT aps_search.purge_files(ARRAY[?]::bigint[])', [$fileId]);
			$this->tombstone($fileId, 'deleted');
			return 'purged';
		}
		if ($this->tree->isDirectory($fileId)) {
			if ($kind === 'acl') {
				$this->outbox->markAclDirtySubtree($fileId);
				return 'acl_marked';
			}
			$this->outbox->expandSubtree($fileId);
			return 'expanded';
		}
		if ($node['path'] !== 'files' && !str_starts_with($node['path'], 'files/')) {
			$this->erase($fileId, 'trashed');
			$this->tombstone($fileId, 'trashed');
			return 'erased_trashed';
		}
		try {
			$excluded = $this->exclusion->isExcluded($fileId);
		} catch (ExclusionUnavailable $e) {
			$this->logger->warning('buscador_documental: exclusion state unavailable (' . get_class($e) . ')');
			return 'retry';
		}
		if ($excluded) {
			$this->erase($fileId, 'excluded');
			return 'erased_excluded';
		}
		if (!$this->predicate->inScope($this->rules->current(), $fileId)) {
			if ($this->db->rows('SELECT 1 FROM aps_search.file_state WHERE file_id = ?', [$fileId]) !== []) {
				$this->erase($fileId, 'out_of_scope');
				return 'erased_out_of_scope';
			}
			return 'ignored';
		}
		if ($this->settings->isPaused()) {
			return 'held';
		}
		if ($kind === 'acl') {
			$this->acl->write($fileId);
			return 'acl_written';
		}
		return $this->indexer->enqueue($fileId) ? 'enqueued' : 'ignored';
	}

	/** Refreshes principals of files flagged dirty, oldest first, ahead of any backfill. @return int files refreshed */
	public function refreshDirty(int $limit): int {
		$ids = $this->db->rows('SELECT file_id FROM aps_search.file_state WHERE acl_dirty ORDER BY acl_dirty_since, file_id LIMIT ?', [$limit]);
		foreach ($ids as $r) {
			$id = (int)$r['file_id'];
			$this->acl->write($id);
			$this->db->execute('UPDATE aps_search.file_state SET acl_dirty = false, acl_dirty_since = NULL WHERE file_id = ?', [$id]);
		}
		return count($ids);
	}

	private function erase(int $fileId, string $reason): void {
		$this->db->rows('SELECT aps_search.erase_files(ARRAY[?]::bigint[], ?)', [$fileId, $reason]);
	}

	private function tombstone(int $fileId, string $reason): void {
		$this->db->execute(
			"INSERT INTO aps_search.tombstone (file_id, reason, purge_after) VALUES (?, ?, now() + make_interval(days => ?))
			 ON CONFLICT (file_id) DO UPDATE SET reason = EXCLUDED.reason, created_at = now(), purge_after = EXCLUDED.purge_after",
			[$fileId, $reason, self::TRASH_LEDGER_DAYS],
		);
	}
}
