<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Job;

use OCA\BuscadorDocumental\Search\VecBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/** Every five minutes: queue files that lack vectors, refresh vector coverage, ready a full-enough generation. */
class VecBackfillJob extends TimedJob {
	public const BATCH = 1000;

	public function __construct(ITimeFactory $time, private VecBackfill $backfill, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$this->backfill->tick(self::BATCH);
		} catch (Throwable $e) {
			$this->logger->warning('buscador_documental: vector backfill failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}
}
