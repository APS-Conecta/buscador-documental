<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Job;

use DateTimeImmutable;
use OCA\BuscadorDocumental\Reconcile\Reconciler;
use OCA\BuscadorDocumental\Reconcile\ReconcileWindow;
use OCA\BuscadorDocumental\Scope\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/** Nightly reconciliation: fires every few minutes, acts only inside the window, resumes from its cursor. No arguments. */
class Reconcile extends TimedJob {
	public function __construct(ITimeFactory $time, private Reconciler $reconciler, private Settings $settings, private IDateTimeZone $zone, private LoggerInterface $logger) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$now = new DateTimeImmutable('now', $this->zone->getDefaultTimeZone());      // jobs run in UTC; the window is the server's local night
		try {
			$window = $this->settings->get('reconcile_window') ?? ReconcileWindow::DEFAULT;
			if (!$this->reconciler->due($now, $window)) {
				return;
			}
			$this->reconciler->runOnce(60, $now->format('Y-m-d'));            // short and resumable: in AJAX cron mode a job holds a web worker
		} catch (Throwable $e) {
			$this->logger->warning('buscador_documental: reconciliation step failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}
}
