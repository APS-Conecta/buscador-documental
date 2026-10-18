<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

use InvalidArgumentException;
use OCA\BuscadorDocumental\Db\IndexDb;

/** Small key/value settings in aps_search.settings, readable by the worker gate in SQL (pause) and by the app. */
class Settings {
	public const MODES = ['opt_in', 'all_readable'];

	public function __construct(private IndexDb $db) {
	}

	public function get(string $key): ?string {
		$rows = $this->db->rows('SELECT value FROM aps_search.settings WHERE key = ?', [$key]);
		return $rows === [] ? null : (string)$rows[0]['value'];
	}

	public function set(string $key, string $value): void {
		$this->db->execute(
			'INSERT INTO aps_search.settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value',
			[$key, $value],
		);
	}

	public function delete(string $key): void {
		$this->db->execute('DELETE FROM aps_search.settings WHERE key = ?', [$key]);
	}

	/** The mode in force. A stored `all_readable` without the legal gate is ignored: the safe mode wins. */
	public function scopeMode(): string {
		$stored = $this->get('scope_mode') ?? 'opt_in';
		return $stored === 'all_readable' && $this->legalGateRecorded() ? 'all_readable' : 'opt_in';
	}

	public function setScopeMode(string $mode): void {
		if (!in_array($mode, self::MODES, true)) {
			throw new InvalidArgumentException("Unknown scope mode '$mode'");
		}
		if ($mode === 'all_readable' && !$this->legalGateRecorded()) {
			throw new ScopeLocked('all_readable needs counsel confirmation and a recorded DPIA first (ADR-0006).');
		}
		$this->set('scope_mode', $mode);
		$this->requestEvaluation();
	}

	/** The rules or the mode changed: the reconciliation should look at everything soon, not at the next window. */
	public function requestEvaluation(): void {
		$this->set('reconcile_now', '1');
	}

	public function isPaused(): bool {
		return $this->get('paused') === '1';
	}

	public function setPaused(bool $paused): void {
		$this->set('paused', $paused ? '1' : '0');
	}

	private function legalGateRecorded(): bool {
		return $this->get('counsel_confirmed_at') !== null && $this->get('dpia_ref') !== null;
	}
}
