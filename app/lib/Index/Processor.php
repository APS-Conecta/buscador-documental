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
use OCA\BuscadorDocumental\Scope\ScopeSet;
use OCA\BuscadorDocumental\Scope\Settings;
use Psr\Log\LoggerInterface;

/**
 * Re-evaluates one file from the truth (file cache, tags, scope rules), never from the event that pointed at it
 * (ADR-0013). Removals come first and are never held back; additions wait for a pause to end.
 *
 * Outcomes: purged, erased_trashed, erased_excluded, erased_out_of_scope, ignored, held, retry, enqueued, acl_written,
 * expanded, folder_unchanged, acl_marked, rule.
 */
class Processor {
	public const TRASH_LEDGER_DAYS = 30;

	private Outbox $outbox;
	private ?ScopeSet $batchScope = null;

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

	/** One batch evaluates many rows with the same rules and settings: read them once (and not once per row). */
	public function beginBatch(): void {
		$this->batchScope = $this->rules->current();
	}

	public function endBatch(): void {
		$this->batchScope = null;
	}

	public function process(int $fileId, string $kind): string {
		if ($kind === 'rule') {
			// The exclusion tag was deleted: whatever it had excluded must be looked at again.
			$this->db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT file_id, 'file' FROM aps_search.file_state WHERE skip_reason = 'excluded' ON CONFLICT DO NOTHING");
			return 'rule';
		}
		$node = $this->tree->node($fileId);
		if ($node === null) {
			if (!$this->known($fileId)) {
				return 'ignored';                       // an id that was never indexed leaves no state and no tombstone behind
			}
			$this->db->rows('SELECT aps_search.purge_files(ARRAY[?]::bigint[])', [$fileId]);
			$this->tombstone($fileId, 'deleted');
			return 'purged';
		}
		if ($this->tree->isDirectory($fileId)) {
			if ($kind === 'acl') {
				$this->outbox->markAclDirtySubtree($fileId);
				return 'acl_marked';
			}
			// 'subtree' is a tag change on the folder: it can change every verdict below, and nothing in the cache shows it.
			if ($kind === 'file') {
				try {
					$needed = $this->subtreeNeedsEvaluation($fileId, $node['parent']);
				} catch (ExclusionUnavailable $e) {
					return $this->unavailable($e);
				}
				if (!$needed) {
					return 'folder_unchanged';
				}
			}
			$this->outbox->expandSubtree($fileId);
			return 'expanded';
		}
		if ($node['path'] !== 'files' && !str_starts_with($node['path'], 'files/')) {
			if (!$this->known($fileId)) {
				return 'ignored';                       // versions, upload chunks and cache files that never concerned the index
			}
			$this->erase($fileId, 'trashed');
			$this->tombstone($fileId, 'trashed');
			return 'erased_trashed';
		}
		// Removals first, and they need nothing but the rules: leaving the scope is erased even while the tag service is down.
		if (!$this->predicate->inScope($this->scope(), $fileId)) {
			if ($this->known($fileId)) {
				$this->erase($fileId, 'out_of_scope');
				return 'erased_out_of_scope';
			}
			return 'ignored';
		}
		try {
			$excluded = $this->exclusion->isExcluded($fileId);
		} catch (ExclusionUnavailable $e) {
			return $this->unavailable($e);
		}
		if ($excluded) {
			$this->erase($fileId, 'excluded');
			return 'erased_excluded';
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

	/**
	 * Refreshes the principals of files marked dirty, newest change first (a real revocation must not wait behind the
	 * weekly sweep). The mark is cleared BEFORE the write, so a change that arrives meanwhile marks the file again and is
	 * not lost; a file whose write fails is marked again and the others carry on. Erased files are never refreshed.
	 *
	 * @return int files refreshed
	 */
	public function refreshDirty(int $limit): int {
		$this->db->execute("UPDATE aps_search.file_state SET acl_dirty = false, acl_dirty_since = NULL WHERE acl_dirty AND text_state = 'skipped'");
		$ids = $this->db->rows('SELECT file_id FROM aps_search.file_state WHERE acl_dirty ORDER BY acl_dirty_since DESC, file_id LIMIT ?', [$limit]);
		$done = 0;
		foreach ($ids as $r) {
			$id = (int)$r['file_id'];
			$this->db->execute('UPDATE aps_search.file_state SET acl_dirty = false, acl_dirty_since = NULL WHERE file_id = ?', [$id]);
			try {
				$this->acl->write($id);
				$done++;
			} catch (\Throwable $e) {
				$this->db->execute('UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = coalesce(acl_dirty_since, now()) WHERE file_id = ?', [$id]);
				$this->logger->warning('buscador_documental: permission refresh failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
			}
		}
		return $done;
	}

	/**
	 * Can a plain cache event on this folder have changed a verdict below it? Nextcloud fires the same event for the
	 * parent's mtime correction on every child write (the child brings its own event) and for a move, so the answer
	 * comes from the index: yes when the folder has a new parent (moved, trashed, into or out of a scope root), or when
	 * it is in scope, not excluded and nothing below it is indexed yet (moved or copied in, restored). A rename in place
	 * keeps the parent: scope rules, exclusion, shares and the folder filter are keyed by ids.
	 *
	 * Every indexed descendant is compared, not a sample: a folder moved back halfway through an expansion leaves files
	 * whose ancestors carry the other chain, and no reconcile pass compares ancestors. The exclusion test is needed
	 * because erase_files deletes file_meta: an excluded folder always reads as unindexed, and every write below it would
	 * erase each child again. The chain stops below a storage root (FileFactsSource), so its parent is compared as NULL.
	 * One aggregate over file_meta_ancestors_idx, not two EXISTS: those plan as a LIMIT 1 sequential scan, which reads
	 * the whole table whenever nothing matches (an unchanged folder, a folder with nothing indexed).
	 *
	 * @throws ExclusionUnavailable
	 */
	private function subtreeNeedsEvaluation(int $folderId, int $parent): bool {
		$r = $this->db->rows(
			'SELECT count(*) FILTER (WHERE ancestors[array_position(ancestors, ?::bigint) + 1] IS DISTINCT FROM ?::bigint) AS moved,
				count(*) AS indexed
			 FROM aps_search.file_meta WHERE ancestors @> ARRAY[?]::bigint[]',
			[$folderId, $parent > 0 ? $parent : null, $folderId],
		)[0];
		if ((int)$r['moved'] > 0) {
			return true;
		}
		return (int)$r['indexed'] === 0 && $this->predicate->inScope($this->scope(), $folderId) && !$this->exclusion->isExcluded($folderId);
	}

	private function unavailable(ExclusionUnavailable $e): string {
		$this->logger->warning('buscador_documental: exclusion state unavailable (' . get_class($e) . ')');
		return 'retry';
	}

	private function scope(): ScopeSet {
		return $this->batchScope ?? $this->rules->current();
	}

	private function known(int $fileId): bool {
		return $this->db->rows('SELECT 1 FROM aps_search.file_state WHERE file_id = ?', [$fileId]) !== [];
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
