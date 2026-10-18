<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ui;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\Recheck;
use OCA\BuscadorDocumental\Status\Reindexer;
use OCP\IGroupManager;
use Throwable;

/**
 * The "Texto reconocido" tab of the Files sidebar. The recognised text of a file is exactly what a person who can download that file
 * could read by opening it, so it is served only after the live recheck (readable, not excluded) says the person may have the bytes.
 * Unreadable, excluded and unknown files all get the same null answer.
 */
class FileText {
	public const MAX_CHARS = 200000;

	public function __construct(private IndexDb $db, private Recheck $recheck, private IGroupManager $groups, private Reindexer $reindexer) {
	}

	/** @return array<string, mixed>|null null: not found for this person */
	public function text(string $uid, int $fileId): ?array {
		$r = $this->recheck->filter($uid, [$fileId])[$fileId] ?? null;
		if ($r === null) {
			return null;
		}
		if (!$r->snippetAllowed) {
			return ['status' => 'restricted'];                   // readable, but the share forbids download: the text is the content
		}
		$rows = $this->db->rows('SELECT text_state, skip_reason, etag FROM aps_search.file_state WHERE file_id = ?', [$fileId]);
		if ($rows === []) {
			return ['status' => 'none'];
		}
		$row = $rows[0];
		if ($row['text_state'] === 'skipped') {
			// Policy reasons (excluded, out of scope, trashed, deleted) are not "problems with this file": nothing to show or retry.
			return in_array((string)$row['skip_reason'], AdminActions::PROBLEM_REASONS, true) ? ['status' => 'skipped', 'reason' => (string)$row['skip_reason']] : ['status' => 'none'];
		}
		if ($row['text_state'] !== 'indexed' || (string)$row['etag'] !== (string)$r->node->getEtag()) {
			return ['status' => 'pending'];                      // never the text of an older version
		}
		$pages = [];
		$chars = 0;
		$truncated = false;
		$below = $this->uncertainBelow();
		// One row more than the limit tells whether pages were left out; each text is cut in the database, never loaded whole.
		$rows = $this->db->rows('SELECT page_no, left(text, ?) AS text, length(text) AS len, ocr_conf, source FROM aps_search.page WHERE file_id = ? ORDER BY page_no LIMIT 1001', [self::MAX_CHARS + 1, $fileId]);
		if (count($rows) > 1000) {
			$truncated = true;
			$rows = array_slice($rows, 0, 1000);
		}
		foreach ($rows as $p) {
			$text = (string)$p['text'];
			if ($chars + mb_strlen($text) > self::MAX_CHARS) {
				$text = mb_substr($text, 0, max(0, self::MAX_CHARS - $chars));
				$truncated = true;
			}
			$chars += mb_strlen($text);
			$conf = $p['ocr_conf'] === null ? null : (float)$p['ocr_conf'];
			$pages[] = ['page_no' => (int)$p['page_no'], 'text' => $text, 'ocr_conf' => $conf, 'source' => (string)$p['source'], 'uncertain' => $conf !== null && $conf < $below];
			if ($chars >= self::MAX_CHARS) {
				$truncated = $truncated || count($pages) < count($rows) || (int)$p['len'] > mb_strlen($text);
				break;
			}
		}
		return ['status' => 'indexed', 'pages' => $pages, 'truncated' => $truncated];
	}

	private function uncertainBelow(): float {
		return (float)((new \OCA\BuscadorDocumental\Scope\Settings($this->db))->get('uncertain_below') ?? 60);
	}

	/** @return string queued|forbidden (the same word for "not yours", "not found" and "cannot be re-read") */
	public function reread(string $uid, int $fileId): string {
		$r = $this->recheck->filter($uid, [$fileId])[$fileId] ?? null;
		if ($r === null || !$this->mayReread($uid, $r)) {
			return 'forbidden';
		}
		return $this->reindexer->reread($fileId) ? 'queued' : 'forbidden';
	}

	private function mayReread(string $uid, \OCA\BuscadorDocumental\Search\RecheckResult $r): bool {
		try {
			if ($r->node->getOwner()?->getUID() === $uid) {
				return true;
			}
		} catch (Throwable) {
		}
		return $this->groups->isAdmin($uid);
	}
}
