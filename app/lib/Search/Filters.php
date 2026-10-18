<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Db\IndexDb;

/** The narrowing filters of a search. They go into SQL before ranking, in every leg (S4 design section 3). */
final class Filters {
	/**
	 * @param list<string> $mimes mime types (already expanded from groups)
	 * @param ?int $from,$to mtime bounds, inclusive (epoch seconds)
	 * @param ?string $owner 'mine' or 'shared' (needs $uid, set by forUser())
	 */
	public function __construct(
		public readonly array $mimes = [],
		public readonly ?int $from = null,
		public readonly ?int $to = null,
		public readonly ?int $folderId = null,
		public readonly ?string $owner = null,
		public readonly bool $uncertain = false,
		public readonly ?string $uid = null,
		public readonly float $uncertainBelow = 60.0,
	) {
	}

	/** The same filters bound to the caller and to the instance's "uncertain reading" threshold. */
	public function forUser(string $uid, float $uncertainBelow): self {
		return new self($this->mimes, $this->from, $this->to, $this->folderId, $this->owner, $this->uncertain, $uid, $uncertainBelow);
	}

	public function isEmpty(): bool {
		return !$this->hasFileLevel() && !$this->uncertain;
	}

	/** Anything that needs file_meta. */
	public function hasFileLevel(): bool {
		return $this->mimes !== [] || $this->from !== null || $this->to !== null || $this->folderId !== null || $this->owner !== null;
	}

	/** The filter that looks at a page of a chunk and so cannot narrow a count of files cheaply. */
	public function needsChunk(): bool {
		return $this->uncertain;
	}

	/**
	 * @param string $chunk alias of the chunk table when the chunk-level filter applies, or '' to leave it out
	 * @return array{0: string, 1: list<mixed>} an `AND ...` fragment over alias `m` (file_meta) and its parameters
	 */
	public function sql(string $chunk = ''): array {
		$sql = '';
		$params = [];
		if ($this->mimes !== []) {
			$sql .= ' AND m.mime = ANY(?::text[])';
			$params[] = IndexDb::textArray($this->mimes);
		}
		if ($this->from !== null) {
			$sql .= ' AND m.mtime >= ?';
			$params[] = $this->from;
		}
		if ($this->to !== null) {
			$sql .= ' AND m.mtime <= ?';
			$params[] = $this->to;
		}
		if ($this->folderId !== null) {
			$sql .= ' AND m.ancestors @> ARRAY[?]::bigint[]';
			$params[] = $this->folderId;
		}
		if ($this->owner !== null && $this->uid !== null) {
			$sql .= $this->owner === 'mine' ? ' AND m.owner = ?' : ' AND m.owner IS DISTINCT FROM ?';
			$params[] = $this->uid;
		}
		if ($this->uncertain && $chunk !== '') {
			$sql .= " AND EXISTS (SELECT 1 FROM aps_search.page pg WHERE pg.file_id = $chunk.file_id AND pg.page_no = $chunk.page_no AND pg.ocr_conf IS NOT NULL AND pg.ocr_conf < ?)";
			$params[] = $this->uncertainBelow;
		}
		return [$sql, $params];
	}
}
