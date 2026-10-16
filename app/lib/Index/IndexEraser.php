<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

use OCA\BuscadorDocumental\Db\IndexDb;

/**
 * Synchronous erase (A-SEC-3, H1): one SQL function, one transaction, same lock order as the worker functions.
 * Deletes page, chunk, file_acl, file_meta and the file's jobs, and marks file_state skipped(reason).
 * The listener that calls this on tag assignment is STUB(S3-listener-rag-excluded).
 */
class IndexEraser {
	public function __construct(private IndexDb $db) {
	}

	public function erase(int $fileId, string $reason): void {
		$this->db->rows('SELECT aps_search.erase_file(?, ?)', [$fileId, $reason]);
	}
}
