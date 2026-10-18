<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Job;

use OCA\BuscadorDocumental\Job\VecBackfillJob;
use OCA\BuscadorDocumental\Search\VecBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VecBackfillJobTest extends TestCase {
	private function runJob(VecBackfill $backfill, LoggerInterface $log): void {
		$job = new VecBackfillJob($this->createMock(ITimeFactory::class), $backfill, $log);
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, null);
	}

	public function testItTicksWithABoundedBatch(): void {
		$b = $this->createMock(VecBackfill::class);
		$b->expects($this->once())->method('tick')->with(VecBackfillJob::BATCH)->willReturn(['queued' => 0, 'coverage' => [], 'ready' => []]);
		$this->runJob($b, $this->createMock(LoggerInterface::class));
		$this->assertGreaterThan(0, VecBackfillJob::BATCH);
		$this->assertLessThanOrEqual(5000, VecBackfillJob::BATCH);
	}

	public function testAFailureIsLoggedAsClassAndPlaceAndNeverStopsTheCronRun(): void {
		$b = $this->createMock(VecBackfill::class);
		$b->method('tick')->willThrowException(new \RuntimeException('SECRET row text'));
		$log = $this->createMock(LoggerInterface::class);
		$seen = '';
		$log->method('warning')->willReturnCallback(function (string $m) use (&$seen): void { $seen = $m; });
		$this->runJob($b, $log);
		$this->assertStringContainsString('RuntimeException', $seen);
		$this->assertStringNotContainsString('SECRET', $seen);
	}
}
