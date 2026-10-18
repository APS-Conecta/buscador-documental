<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Worker;

use OCA\BuscadorDocumental\Db\IndexDb;

/** Does this worker hold the current, unexpired claim on this file? (M6: checked on every meta and content request) */
class ClaimGuard {
	public const MAX_ACTIVE_PER_WORKER = 4;

	public function __construct(private IndexDb $db) {
	}

	/** @return array{etag:string}|null null when there is no such active claim */
	public function activeClaim(string $workerId, int $jobId, int $epoch, int $fileId): ?array {
		$rows = $this->db->rows(
			"SELECT claimed_etag FROM aps_search.job
			 WHERE id = ? AND lane = 'text' AND file_id = ? AND claimed_by = ? AND lease_epoch = ? AND lease_until > clock_timestamp()",
			[$jobId, $fileId, $workerId, $epoch],
		);
		return $rows === [] ? null : ['etag' => (string)$rows[0]['claimed_etag']];
	}

	/** An administrator paused indexing: the bytes and the meta are not served either (the claim_job gate covers new claims). */
	public function isPaused(): bool {
		return $this->db->rows("SELECT 1 FROM aps_search.settings WHERE key = 'paused' AND value = '1'") !== [];
	}

	/** True when the worker holds more active claims than the cap allows (derived from the DB, never a cache counter). */
	public function overCap(string $workerId): bool {
		$rows = $this->db->rows(
			'SELECT count(*) AS n FROM aps_search.job WHERE claimed_by = ? AND lease_until > clock_timestamp()',
			[$workerId],
		);
		return (int)$rows[0]['n'] > self::MAX_ACTIVE_PER_WORKER;
	}
}
