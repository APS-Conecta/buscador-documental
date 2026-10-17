<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Job;

use OCA\BuscadorDocumental\Reconcile\GroupfolderFingerprint;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/** Team-folder grants and ACL rules have no typed event: look every 15 minutes. */
class GroupfolderPoll extends TimedJob {
	public function __construct(ITimeFactory $time, private GroupfolderFingerprint $fingerprint, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$this->fingerprint->check();
		} catch (Throwable $e) {
			$this->logger->warning('buscador_documental: team-folder poll failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}
}
