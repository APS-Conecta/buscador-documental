<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

/** The narrowing filters of a search. They go into SQL before ranking, in every leg (S4 design section 3). */
final class Filters {
	/** @param list<string> $mimes mime types (already expanded from groups) @param ?int $from,$to mtime bounds, inclusive (epoch seconds) */
	public function __construct(
		public readonly array $mimes = [],
		public readonly ?int $from = null,
		public readonly ?int $to = null,
		public readonly ?int $folderId = null,
	) {
	}

	public function isEmpty(): bool {
		return $this->mimes === [] && $this->from === null && $this->to === null && $this->folderId === null;
	}

	/** @return array{0: string, 1: list<mixed>} an `AND ...` fragment over alias `m` (file_meta) and its parameters */
	public function sql(): array {
		$sql = '';
		$params = [];
		if ($this->mimes !== []) {
			$sql .= ' AND m.mime = ANY(?::text[])';
			$params[] = \OCA\BuscadorDocumental\Db\IndexDb::textArray($this->mimes);
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
		return [$sql, $params];
	}
}
