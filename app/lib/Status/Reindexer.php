<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Status;

use OCA\BuscadorDocumental\Db\IndexDb;

/** Queues files for re-evaluation through the outbox; the processor decides what to do with each (level-triggered, ADR-0013). */
class Reindexer {
	public function __construct(private IndexDb $db) {
	}

	public function enqueue(int $fileId): int {
		return $this->db->execute("INSERT INTO aps_search.outbox (file_id, kind) VALUES (?, 'file') ON CONFLICT DO NOTHING", [$fileId]);
	}

	/** Skip reasons decided by the scope and the tags: a retry cannot change them, re-evaluation does. */
	private const POLICY = "('excluded', 'out_of_scope', 'trashed', 'user_deleted')";

	/** Put a file the worker gave up on back in the queue. False when it is not skipped for a reason a retry can change. */
	public function retry(int $fileId): bool {
		$n = $this->db->execute(
			"UPDATE aps_search.file_state SET text_state = 'pending', skip_reason = NULL, updated_at = now()
			 WHERE file_id = ? AND text_state = 'skipped' AND skip_reason NOT IN " . self::POLICY,
			[$fileId],
		);
		if ($n === 0) {
			return false;
		}
		$this->enqueue($fileId);
		return true;
	}

	/** Read a file again even though its text is current (the owner or an administrator asks). False for policy skips and unknown files. */
	public function reread(int $fileId): bool {
		$n = $this->db->execute(
			"UPDATE aps_search.file_state SET text_state = 'pending', skip_reason = NULL, updated_at = now()
			 WHERE file_id = ? AND (text_state <> 'skipped' OR skip_reason NOT IN " . self::POLICY . ')',
			[$fileId],
		);
		if ($n === 0) {
			return false;
		}
		$this->enqueue($fileId);
		return true;
	}

	/** Every file the index knows about. */
	public function enqueueAll(): int {
		return $this->db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT file_id, 'file' FROM aps_search.file_state ON CONFLICT DO NOTHING");
	}
}
