<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Scope\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Ask for a reconciliation now instead of tonight: after a restore, or after fixing a mass change the events missed. */
class Reconcile extends Command {
	public function __construct(private Settings $settings, private AuditLog $audit) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:reconcile')->setDescription('Ask for a full reconciliation at the next opportunity, without waiting for the night window');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->settings->requestEvaluation();
		$this->audit->record(Actor::Occ, 'reconcile', null, []);
		$output->writeln('reconciliation requested: it runs on the next pass of the background job (use cron)');
		return 0;
	}
}
