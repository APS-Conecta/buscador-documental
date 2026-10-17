<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Job;

use DateTimeImmutable;
use DateTimeZone;
use OCA\BuscadorDocumental\Job\Reconcile;
use OCA\BuscadorDocumental\Reconcile\Reconciler;
use OCA\BuscadorDocumental\Scope\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReconcileJobTest extends TestCase {
	private function runJob(?string $window, string $zone): array {
		$seen = [];
		$reconciler = $this->createMock(Reconciler::class);
		$reconciler->method('due')->willReturnCallback(static function (DateTimeImmutable $now, string $w) use (&$seen): bool {
			$seen = ['zone' => $now->getTimezone()->getName(), 'window' => $w];
			return false;
		});
		$settings = $this->createMock(Settings::class);
		$settings->method('get')->willReturn($window);
		$tz = $this->createMock(IDateTimeZone::class);
		$tz->method('getDefaultTimeZone')->willReturn(new DateTimeZone($zone));
		$job = new Reconcile($this->createMock(ITimeFactory::class), $reconciler, $settings, $tz, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		return $seen;
	}

	public function testTheWindowIsJudgedInTheServersConfiguredZoneNotInUtc(): void {
		$this->assertSame(['zone' => 'America/Santiago', 'window' => '01:00-05:00'], $this->runJob(null, 'America/Santiago'));
	}

	public function testAConfiguredWindowIsUsed(): void {
		$this->assertSame('22:00-04:00', $this->runJob('22:00-04:00', 'Europe/Madrid')['window']);
	}
}
