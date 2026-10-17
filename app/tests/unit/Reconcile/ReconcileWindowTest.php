<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Reconcile;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\BuscadorDocumental\Reconcile\ReconcileWindow;
use PHPUnit\Framework\TestCase;

class ReconcileWindowTest extends TestCase {
	private function at(string $time): DateTimeImmutable {
		return new DateTimeImmutable("2026-10-17 $time");
	}

	/** @dataProvider cases */
	public function testWindows(string $window, string $time, bool $expected): void {
		$this->assertSame($expected, ReconcileWindow::contains($window, $this->at($time)), "$window at $time");
	}

	public static function cases(): array {
		return [
			'inside' => ['01:00-05:00', '03:30', true],
			'start is inclusive' => ['01:00-05:00', '01:00', true],
			'end is exclusive' => ['01:00-05:00', '05:00', false],
			'before' => ['01:00-05:00', '00:59', false],
			'after' => ['01:00-05:00', '12:00', false],
			'across midnight, evening side' => ['22:00-04:00', '23:15', true],
			'across midnight, morning side' => ['22:00-04:00', '03:59', true],
			'across midnight, outside' => ['22:00-04:00', '12:00', false],
			'all day' => ['00:00-00:00', '15:45', true],
		];
	}

	/** @dataProvider invalid */
	public function testInvalidWindowsAreRefused(string $window): void {
		$this->expectException(InvalidArgumentException::class);
		ReconcileWindow::contains($window, $this->at('02:00'));
	}

	public static function invalid(): array {
		return [[''], ['night'], ['1-5'], ['25:00-04:00'], ['01:00-04:61'], ['01:00']];
	}
}
