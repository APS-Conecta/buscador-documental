<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ui;

use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Search\Recheck;

/** The folders the "Carpeta" filter offers: indexed folders that this person can read right now. Others are not mentioned. */
class FolderList {
	public function __construct(private ScopeRules $rules, private Recheck $recheck) {
	}

	/** @return list<array{id: int, path: string}> */
	public function forUser(string $uid): array {
		$ids = [];
		foreach ($this->rules->all() as $r) {
			if ($r['kind'] === 'folder' && $r['file_id'] !== null) {
				$ids[] = $r['file_id'];
			}
		}
		$readable = $ids === [] ? [] : $this->recheck->filter($uid, $ids);
		$out = [];
		foreach ($ids as $id) {
			if (isset($readable[$id])) {
				$path = (string)$readable[$id]->node->getPath();
				$out[] = ['id' => $id, 'path' => preg_match('#^/[^/]+/files(/.*)$#D', $path, $m) === 1 ? $m[1] : '/' . $readable[$id]->node->getName()];
			}
		}
		usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
		return $out;
	}
}
