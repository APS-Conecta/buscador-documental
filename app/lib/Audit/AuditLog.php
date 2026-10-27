<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Audit;

use InvalidArgumentException;
use OCA\BuscadorDocumental\Db\IndexDb;

/** Administrative actions, pseudonymised, with a closed detail schema (no query text, no content, no names). */
class AuditLog {
	public const ACTIONS = ['scope.add', 'scope.remove', 'scope.mode', 'pause', 'resume', 'notice.ack', 'purge_user', 'erase', 'reconcile', 'settings.set', 'profile.set', 'profile.activate', 'profile.drop', 'semantic.set', 'window.set', 'throttle.set', 'secret.rotate', 'secret.adopt'];

	public function __construct(private IndexDb $db, private Pseudonyms $pseudonyms) {
	}

	/** @param array<string, int|bool|string> $detail */
	public function record(Actor|string $actor, string $action, ?string $subjectUid, array $detail = []): void {
		if (!in_array($action, self::ACTIONS, true)) {
			throw new InvalidArgumentException("Unknown audit action '$action'");
		}
		$detail = AuditDetail::check($detail);
		$this->db->execute(
			'INSERT INTO aps_search.audit_log (actor, action, subject_token, detail) VALUES (?, ?, ?, ?::jsonb)',
			[
				$this->pseudonyms->actorToken($actor),
				$action,
				$subjectUid === null ? null : $this->pseudonyms->tokenFor($subjectUid),
				json_encode($detail === [] ? new \stdClass() : $detail, JSON_THROW_ON_ERROR),
			],
		);
	}

	/** @return int rows removed */
	public function prune(int $retentionDays): int {
		return $this->db->execute("DELETE FROM aps_search.audit_log WHERE at < now() - make_interval(days => ?)", [$retentionDays]);
	}
}
