<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Reconcile;

use DateTimeImmutable;
use InvalidArgumentException;

/** The daily window "HH:MM-HH:MM" (local time, may cross midnight; equal ends mean all day) in which reconciliation runs. */
final class ReconcileWindow {
	public const DEFAULT = '01:00-05:00';

	public static function contains(string $window, DateTimeImmutable $now): bool {
		if (preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $window, $m) !== 1) {
			throw new InvalidArgumentException("Window '$window' must look like HH:MM-HH:MM");
		}
		[$h1, $m1, $h2, $m2] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
		if ($h1 > 23 || $h2 > 23 || $m1 > 59 || $m2 > 59) {
			throw new InvalidArgumentException("Window '$window' has an impossible time");
		}
		$start = $h1 * 60 + $m1;
		$end = $h2 * 60 + $m2;
		$at = (int)$now->format('G') * 60 + (int)$now->format('i');
		if ($start === $end) {
			return true;
		}
		return $start < $end ? ($at >= $start && $at < $end) : ($at >= $start || $at < $end);
	}
}
