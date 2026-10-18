<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Status;

/**
 * The time left for one lane: remaining work over the observed rate. The stated margin is +-30 % when the rate is steady;
 * with fewer than 10 completions in the last hour there is no ETA at all rather than a wild one.
 */
final class Eta {
	public const MARGIN = 0.30;
	public const MIN_SAMPLE = 10;

	/**
	 * @param int $doneLastHour completions in the last 60 minutes
	 * @param int $donePrevHour completions in the 60 minutes before that
	 * @param ?int $secondsToWindow seconds until the night window opens, when it is closed
	 * @return array{state: string, eta_seconds: ?int, rate_per_hour: ?float, remaining: int, opens_in_seconds: ?int}
	 */
	public static function estimate(int $remaining, int $doneLastHour, int $donePrevHour, bool $paused, bool $windowOpen, ?int $secondsToWindow): array {
		$out = ['state' => 'ok', 'eta_seconds' => null, 'rate_per_hour' => null, 'remaining' => $remaining, 'opens_in_seconds' => null];
		if ($remaining <= 0) {
			return ['state' => 'done', 'eta_seconds' => 0] + $out;
		}
		if ($paused) {
			return ['state' => 'paused'] + $out;
		}
		if (!$windowOpen) {
			return ['state' => 'window_closed', 'opens_in_seconds' => $secondsToWindow] + $out;
		}
		if ($doneLastHour < self::MIN_SAMPLE) {
			return ['state' => 'unknown'] + $out;
		}
		$rate = $donePrevHour > 0 ? ($doneLastHour + $donePrevHour) / 2 : (float)$doneLastHour;
		return ['rate_per_hour' => (float)$rate, 'eta_seconds' => (int)round($remaining / $rate * 3600)] + $out;
	}
}
