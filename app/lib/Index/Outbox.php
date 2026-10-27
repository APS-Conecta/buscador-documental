<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

use OCA\BuscadorDocumental\Db\IndexDb;

/** The event outbox (ADR-0013): a row means "re-evaluate this file". One pending row per (file, kind). */
class Outbox {
	public const MAX_ATTEMPTS = 5;
	public const STALE_CLAIM_MINUTES = 10;

	public function __construct(private IndexDb $db) {
	}

	public function touch(int $fileId, string $kind): void {
		$this->db->guarded(fn () => $this->db->execute('INSERT INTO aps_search.outbox (file_id, kind) VALUES (?, ?) ON CONFLICT DO NOTHING', [$fileId, $kind]));
	}

	/** @param list<int> $fileIds */
	public function touchMany(array $fileIds, string $kind): void {
		if ($fileIds === []) {
			return;
		}
		$this->db->guarded(fn () => $this->db->execute(
			'INSERT INTO aps_search.outbox (file_id, kind) SELECT DISTINCT unnest(?::bigint[]), ? ON CONFLICT DO NOTHING',
			['{' . implode(',', array_map('intval', $fileIds)) . '}', $kind],
		));
	}

	/**
	 * Takes a batch for processing. The attempt is counted and the row marked claimed at once, so a run that crashes
	 * mid-way cannot leave a row that is retried forever (after MAX_ATTEMPTS it is parked and shows in the status) and a
	 * claim older than STALE_CLAIM_MINUTES belongs to a dead run and is taken again. Rows waiting out a backoff
	 * (not_before) are skipped.
	 *
	 * @return list<array{id: int, file_id: int, kind: string}>
	 */
	public function claim(int $limit): array {
		$rows = $this->db->rows(
			'UPDATE aps_search.outbox SET attempts = attempts + 1, claimed_at = now()
			 WHERE id IN (SELECT id FROM aps_search.outbox
			              WHERE processed_at IS NULL AND attempts < ? AND not_before <= now()
			                AND (claimed_at IS NULL OR claimed_at < now() - make_interval(mins => ?))
			              ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED)
			 RETURNING id, file_id, kind',
			[self::MAX_ATTEMPTS, self::STALE_CLAIM_MINUTES, $limit],
		);
		usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
		return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'file_id' => (int)$r['file_id'], 'kind' => (string)$r['kind']], $rows);
	}

	/** @param list<int> $ids */
	public function done(array $ids): void {
		if ($ids !== []) {
			$this->db->execute('UPDATE aps_search.outbox SET processed_at = now() WHERE id = ANY(?::bigint[])', ['{' . implode(',', $ids) . '}']);
		}
	}

	/** Gives the attempt back: the row was only held (paused), not tried. @param list<int> $ids */
	public function release(array $ids): void {
		$this->handBack($ids, 'attempts = greatest(attempts - 1, 0)');
	}

	/** The evaluation failed: try again later, waiting longer each time (2, 4, 8, 16 minutes), so a short outage cannot use up every attempt at once. @param list<int> $ids */
	public function retryLater(array $ids): void {
		$this->handBack($ids, 'not_before = now() + make_interval(mins => power(2, attempts)::int)');
	}

	/** Queues every file (not folder) below a folder. Set-based over the parent index. */
	public function expandSubtree(int $folderId): int {
		return $this->db->execute(
			"INSERT INTO aps_search.outbox (file_id, kind) SELECT d.fileid, 'file' FROM (" . self::descendantFiles() . ') d ON CONFLICT DO NOTHING',
			[$folderId],
		);
	}

	/** Marks the already-indexed files below a folder as needing a principals refresh. */
	public function markAclDirtySubtree(int $folderId): int {
		return $this->db->execute(
			'UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = coalesce(acl_dirty_since, now())
			 WHERE text_state <> \'skipped\' AND file_id IN (SELECT d.fileid FROM (' . self::descendantFiles() . ') d)',
			[$folderId],
		);
	}

	public function prune(int $days = 7): int {
		return $this->db->execute('DELETE FROM aps_search.outbox WHERE processed_at < now() - make_interval(days => ?)', [$days]);
	}

	/** Hands claimed rows back; a row whose (file, kind) already has another live row is settled as done and that row carries the work (ADR-0013); a unique violation from a concurrent touch() is retried once. @param list<int> $ids */
	private function handBack(array $ids, string $set): void {
		if ($ids === []) {
			return;
		}
		$ids = '{' . implode(',', $ids) . '}';
		$work = function () use ($ids, $set): void {
			$this->db->execute(
				'UPDATE aps_search.outbox o SET processed_at = now() WHERE o.id = ANY(?::bigint[])
				 AND (EXISTS (SELECT 1 FROM aps_search.outbox n WHERE n.file_id = o.file_id AND n.kind = o.kind AND n.id <> o.id
				              AND n.processed_at IS NULL AND n.claimed_at IS NULL AND n.attempts < ' . self::MAX_ATTEMPTS . ')
				   OR EXISTS (SELECT 1 FROM aps_search.outbox n WHERE n.id = ANY(?::bigint[]) AND n.id > o.id
				              AND n.file_id = o.file_id AND n.kind = o.kind))',
				[$ids, $ids],
			);
			$this->db->execute("UPDATE aps_search.outbox SET {$set}, claimed_at = NULL WHERE id = ANY(?::bigint[]) AND processed_at IS NULL", [$ids]);
		};
		try {
			$this->db->guarded($work);
			return;
		} catch (\Throwable $e) {
			if (!self::isUniqueViolation($e)) {
				throw $e;
			}
		}
		$this->db->guarded($work);
	}

	/** Nextcloud wraps the driver's error (OCP\DB\Exception, then Doctrine, then \PDOException), so the SQLSTATE is looked up along the chain. */
	private static function isUniqueViolation(\Throwable $e): bool {
		for (; $e !== null; $e = $e->getPrevious()) {
			if ($e instanceof \PDOException && $e->getCode() === '23505') {
				return true;
			}
		}
		return false;
	}

	private static function descendantFiles(): string {
		return "WITH RECURSIVE sub AS (
				SELECT fileid FROM *PREFIX*filecache WHERE parent = ?
				UNION
				SELECT c.fileid FROM *PREFIX*filecache c JOIN sub p ON c.parent = p.fileid
			) SELECT sub.fileid FROM sub JOIN *PREFIX*filecache f ON f.fileid = sub.fileid
			  JOIN *PREFIX*mimetypes m ON m.id = f.mimetype WHERE m.mimetype <> 'httpd/unix-directory'";
	}
}
