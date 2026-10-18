<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ops;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Scope\Settings;

/** Concurrency caps per worker (ADR-0015). `claim_job` reads `cap_text` and `cap_vec`; anything else means the defaults. */
class Throttle {
	public const DEFAULTS = ['text' => 4, 'vec' => 2];

	public function __construct(private Settings $settings, private AuditLog $audit) {
	}

	/** @return array{text: int, vec: int} */
	public function show(): array {
		$out = [];
		foreach (self::DEFAULTS as $lane => $default) {
			$v = $this->settings->get('cap_' . $lane);
			$out[$lane] = $v !== null && ctype_digit($v) && (int)$v >= 1 && (int)$v <= 16 ? (int)$v : $default;
		}
		return $out;
	}

	/** @throws OpsException */
	public function set(?int $text, ?int $vec): void {
		foreach (['text' => $text, 'vec' => $vec] as $lane => $n) {
			if ($n === null) {
				continue;
			}
			if ($n < 1 || $n > 16) {
				throw new OpsException("The cap for $lane must be a whole number from 1 to 16.");
			}
		}
		foreach (['text' => $text, 'vec' => $vec] as $lane => $n) {
			if ($n !== null) {
				$this->settings->set('cap_' . $lane, (string)$n);
			}
		}
		$this->audit->record('occ', 'throttle.set', null, ['cap_text' => $this->show()['text'], 'cap_vec' => $this->show()['vec']]);
	}

	public function reset(): void {
		$this->settings->delete('cap_text');
		$this->settings->delete('cap_vec');
		$this->audit->record('occ', 'throttle.set', null, ['cap_text' => self::DEFAULTS['text'], 'cap_vec' => self::DEFAULTS['vec']]);
	}
}
