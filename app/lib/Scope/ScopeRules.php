<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;

/** The administrator's inclusion rules (ADR-0006, FRD 2026-10-17): folder subtrees and "all team folders". */
class ScopeRules {
	public function __construct(private IndexDb $db, private AuditLog $audit, private Pseudonyms $pseudonyms) {
	}

	/** @return list<array{id: int, kind: string, file_id: ?int}> */
	public function all(): array {
		return array_map(
			static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'file_id' => $r['file_id'] === null ? null : (int)$r['file_id']],
			$this->db->rows('SELECT id, kind, file_id FROM aps_search.scope_rule ORDER BY id'),
		);
	}

	public function current(): ScopeSet {
		$folders = [];
		$groupfolders = false;
		foreach ($this->all() as $r) {
			if ($r['kind'] === 'folder') {
				$folders[] = (int)$r['file_id'];
			} else {
				$groupfolders = true;
			}
		}
		return new ScopeSet((new Settings($this->db))->scopeMode(), $folders, $groupfolders);
	}

	public function addFolder(int $fileId, Actor|string $actor): int {
		return $this->add('folder', $fileId, $actor);
	}

	public function addGroupfolders(Actor|string $actor): int {
		return $this->add('all_groupfolders', null, $actor);
	}

	public function remove(int $id, Actor|string $actor): bool {
		$rows = $this->db->rows('DELETE FROM aps_search.scope_rule WHERE id = ? RETURNING kind', [$id]);
		if ($rows === []) {
			return false;
		}
		$this->audit->record($actor, 'scope.remove', null, ['rule_id' => $id, 'kind' => (string)$rows[0]['kind']]);
		(new Settings($this->db))->requestEvaluation();
		return true;
	}

	/** Changes whenever the rules or the effective mode change: a cheap "did scope move" check for reconciliation. */
	public function fingerprint(): string {
		$parts = array_map(static fn (array $r): string => $r['kind'] . ':' . ($r['file_id'] ?? ''), $this->all());
		sort($parts);
		return md5((new Settings($this->db))->scopeMode() . '|' . implode(',', $parts));
	}

	private function add(string $kind, ?int $fileId, Actor|string $actor): int {
		$existing = $this->db->rows(
			'SELECT id FROM aps_search.scope_rule WHERE kind = ? AND file_id IS NOT DISTINCT FROM ?::bigint',
			[$kind, $fileId],
		);
		if ($existing !== []) {
			return (int)$existing[0]['id'];
		}
		$id = (int)$this->db->rows('INSERT INTO aps_search.scope_rule (kind, file_id, created_by_token) VALUES (?, ?, ?) RETURNING id', [
			$kind, $fileId, $this->pseudonyms->actorToken($actor),
		])[0]['id'];
		$this->audit->record($actor, 'scope.add', null, ['rule_id' => $id, 'kind' => $kind]);
		(new Settings($this->db))->requestEvaluation();
		return $id;
	}
}
