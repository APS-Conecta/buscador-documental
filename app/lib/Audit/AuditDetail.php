<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Audit;

use InvalidArgumentException;

/** The audit detail is a closed schema: ids, counts, flags and short codes. File names, paths, queries and text cannot get in. */
final class AuditDetail {
	private const KEYS = ['rule_id', 'count', 'reason', 'kind', 'mode', 'file_id', 'run_id', 'storage_id', 'paused', 'gen', 'profile', 'window', 'lanes', 'cap_text', 'cap_vec', 'grace_hours'];
	private const CODE = '/^[a-z0-9_.:-]{1,40}$/';

	/** @return array<string, int|bool|string> */
	public static function check(array $detail): array {
		foreach ($detail as $key => $value) {
			if (!in_array($key, self::KEYS, true)) {
				throw new InvalidArgumentException("Audit detail key '$key' is not allowed");
			}
			$ok = is_int($value) || is_bool($value) || (is_string($value) && preg_match(self::CODE, $value) === 1);
			if (!$ok) {
				throw new InvalidArgumentException("Audit detail '$key' must be an integer, a flag or a short code");
			}
		}
		return $detail;
	}
}
