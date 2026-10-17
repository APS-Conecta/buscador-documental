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

	/** Every file the index knows about. */
	public function enqueueAll(): int {
		return $this->db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT file_id, 'file' FROM aps_search.file_state ON CONFLICT DO NOTHING");
	}
}
