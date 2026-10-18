<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Status;

use OCA\BuscadorDocumental\Status\Eta;
use PHPUnit\Framework\TestCase;

class EtaTest extends TestCase {
	public function testASteadyRateGivesAnEtaWithinTheStatedMargin(): void {
		mt_srand(11);
		for ($trial = 0; $trial < 200; $trial++) {
			$rate = mt_rand(20, 600);                                    // files per hour, constant
			$remaining = mt_rand(100, 20000);
			$last = (int)round($rate * (1 + (mt_rand(-10, 10) / 100)));  // what the last two hours showed: the true rate plus up to 10 % noise
			$prev = (int)round($rate * (1 + (mt_rand(-10, 10) / 100)));
			$e = Eta::estimate($remaining, $last, $prev, false, true, null);
			$this->assertSame('ok', $e['state']);
			$truth = $remaining / $rate * 3600;
			$this->assertEqualsWithDelta($truth, $e['eta_seconds'], $truth * Eta::MARGIN, "rate $rate remaining $remaining");
		}
	}

	public function testThinSamplesGiveNoEtaInsteadOfAWildOne(): void {
		$e = Eta::estimate(5000, 3, 0, false, true, null);
		$this->assertSame(['state' => 'unknown', 'eta_seconds' => null, 'rate_per_hour' => null, 'remaining' => 5000, 'opens_in_seconds' => null], $e);
	}

	public function testNothingLeftIsDone(): void {
		$this->assertSame('done', Eta::estimate(0, 0, 0, false, true, null)['state']);
		$this->assertSame(0, Eta::estimate(0, 50, 50, false, true, null)['eta_seconds']);
	}

	public function testPausedAndClosedWindowSayWhyThereIsNoEta(): void {
		$p = Eta::estimate(500, 100, 100, true, true, null);
		$this->assertSame(['paused', null, 500], [$p['state'], $p['eta_seconds'], $p['remaining']]);
		$w = Eta::estimate(500, 100, 100, false, false, 7200);
		$this->assertSame(['window_closed', null, 7200], [$w['state'], $w['eta_seconds'], $w['opens_in_seconds']]);
		$this->assertSame('paused', Eta::estimate(500, 100, 100, true, false, 7200)['state'], 'pause is the stronger reason');
	}

	public function testTheRateIsSmoothedOverTwoHoursButAFreshStartUsesWhatItHas(): void {
		$this->assertEqualsWithDelta(100.0, Eta::estimate(1000, 100, 100, false, true, null)['rate_per_hour'], 1e-9);
		$this->assertEqualsWithDelta(120.0, Eta::estimate(1000, 120, 0, false, true, null)['rate_per_hour'], 1e-9);
		$this->assertEqualsWithDelta(105.0, Eta::estimate(1000, 120, 90, false, true, null)['rate_per_hour'], 1e-9);
	}
}
