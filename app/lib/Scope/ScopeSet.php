<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

/** What is indexable right now: the effective mode plus the administrator's rules. */
final class ScopeSet {
	/** @param list<int> $folderIds file ids of the folders whose whole subtree is included */
	public function __construct(
		public readonly string $mode,
		public readonly array $folderIds,
		public readonly bool $allGroupfolders,
	) {
	}
}
