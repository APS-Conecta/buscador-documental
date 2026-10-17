<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Job;

use OCA\BuscadorDocumental\Index\Outbox;
use OCA\BuscadorDocumental\Index\OutboxRunner;
use OCA\BuscadorDocumental\Index\Processor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Trigger only: all state lives in Postgres (a queued job would vanish before it runs, a crashed timed job stays
 * reserved for 12 hours), and no argument is ever passed because Nextcloud logs job arguments.
 */
class ProcessOutbox extends TimedJob {
	public function __construct(ITimeFactory $time, private OutboxRunner $runner, private Processor $processor, private Outbox $outbox) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->runner->cycle(45, 200, 500);            // permission changes first, then the outbox (up to 45 s of the 60 s interval)
		$this->outbox->prune();
	}
}
