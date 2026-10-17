<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

/**
 * "Is this file in scope?" One predicate for both modes (ADR-0006): all_readable is the same question with every
 * indexable storage as the rule set. Excluded files (`rag-excluded`) are decided elsewhere and always win.
 */
class ScopePredicate {
	private const MAX_DEPTH = 64;

	public function __construct(private FileTree $tree) {
	}

	public function inScope(ScopeSet $set, int $fileId): bool {
		$node = $this->tree->node($fileId);
		if ($node === null || !self::underFiles($node['path'])) {
			return false;                               // trash, versions, app data: never indexed
		}
		$kind = $this->tree->storageKind($node['storage']);
		if ($kind === 'other') {
			return false;
		}
		if ($set->mode === 'all_readable') {
			return true;
		}
		if ($kind === 'groupfolder' && $set->allGroupfolders) {
			return true;
		}
		if ($set->folderIds === []) {
			return false;
		}
		$seen = [];
		$current = $fileId;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			if (in_array($current, $set->folderIds, true)) {
				return true;
			}
			$seen[$current] = true;
			$n = $this->tree->node($current);
			if ($n === null || $n['parent'] <= 0 || isset($seen[$n['parent']])) {
				return false;
			}
			$current = $n['parent'];
		}
		return false;
	}

	private static function underFiles(string $path): bool {
		return $path === 'files' || str_starts_with($path, 'files/');
	}
}
