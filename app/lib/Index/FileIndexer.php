<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\FileFactsSource;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCA\BuscadorDocumental\Db\IndexDb;

/** Puts one file into the index pipeline: state, metadata (with its storage), principals and a text job. Called by the outbox processor. */
class FileIndexer {
	public function __construct(
		private IndexDb $db,
		private FileFactsSource $facts,
		private PrincipalWriter $acl,
		private ExclusionChecker $exclusion,
	) {
	}

	/**
	 * @return bool false when the file is gone or excluded (nothing is written); true when a job exists afterwards
	 * @throws ExclusionUnavailable when exclusion cannot be established (fail closed: nothing is written)
	 */
	public function enqueue(int $fileId): bool {
		$facts = $this->facts->load($fileId);
		if ($facts === null || $this->exclusion->isExcluded($fileId)) {
			return false;
		}
		// A permanent verdict (encrypted, corrupt, ...) stands while the etag is unchanged; only policy skips are re-evaluated.
		$reevaluable = "('excluded', 'out_of_scope', 'trashed', 'user_deleted')";
		$this->db->execute(
			"INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES (?, ?, 'pending')
			 ON CONFLICT (file_id) DO UPDATE SET
				text_state = CASE WHEN aps_search.file_state.etag IS DISTINCT FROM EXCLUDED.etag
					OR (aps_search.file_state.text_state = 'skipped' AND aps_search.file_state.skip_reason IN $reevaluable)
					THEN 'pending' ELSE aps_search.file_state.text_state END,
				skip_reason = CASE WHEN aps_search.file_state.etag IS DISTINCT FROM EXCLUDED.etag
					OR (aps_search.file_state.text_state = 'skipped' AND aps_search.file_state.skip_reason IN $reevaluable)
					THEN NULL ELSE aps_search.file_state.skip_reason END,
				etag = EXCLUDED.etag, updated_at = now()",
			[$fileId, $facts->etag],
		);
		$this->db->execute(
			'INSERT INTO aps_search.file_meta (file_id, name, mime, mtime, storage_id, ancestors) VALUES (?, ?, ?, ?, ?, ?::bigint[])
			 ON CONFLICT (file_id) DO UPDATE SET name = EXCLUDED.name, mime = EXCLUDED.mime, mtime = EXCLUDED.mtime,
				storage_id = EXCLUDED.storage_id, ancestors = EXCLUDED.ancestors',
			[$fileId, $facts->name, $facts->mime, $facts->mtime, $facts->storageId, IndexDb::textArray(array_map('strval', $facts->ancestors))],
		);
		$this->acl->writeFacts($facts);
		// Unclaimed jobs for an older etag are obsolete; claimed ones fail with etag_mismatch and are reclaimed.
		$key = $fileId . ':' . $facts->etag;
		$this->db->execute(
			"DELETE FROM aps_search.job WHERE lane = 'text' AND file_id = ? AND idem_key <> ? AND (lease_until IS NULL OR lease_until <= clock_timestamp())",
			[$fileId, $key],
		);
		$this->db->execute(
			"INSERT INTO aps_search.job (lane, file_id, idem_key)
			 SELECT 'text', ?, ? WHERE EXISTS (SELECT 1 FROM aps_search.file_state WHERE file_id = ? AND text_state = 'pending')
			 ON CONFLICT (idem_key) DO NOTHING",
			[$fileId, $key, $fileId],
		);
		return true;
	}
}
