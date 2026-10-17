<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Status\StatusReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	public function __construct(private StatusReport $report) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:status')->setDescription('Queue, backlog, skipped files and reconciliation (numbers only)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$s = $this->report->snapshot();
		if ($input->getOption('json')) {
			$output->writeln(json_encode($s, JSON_THROW_ON_ERROR));
			return 0;
		}
		$output->writeln(sprintf('mode: %s', $s['mode']));
		$output->writeln('paused: ' . ($s['paused'] ? 'yes' : 'no'));
		$output->writeln('notice acknowledged: ' . ($s['notice_ack_at'] ?? 'no'));
		$output->writeln(sprintf('jobs: %d queued, %d in progress', $s['jobs']['queued'], $s['jobs']['claimed']));
		foreach ($s['states'] as $state => $n) {
			$output->writeln("files $state: $n");
		}
		foreach ($s['skipped'] as $reason => $n) {
			$output->writeln("skipped $reason: $n");
		}
		$output->writeln(sprintf('outbox: %d pending, %d parked after repeated failures', $s['outbox']['pending'], $s['outbox']['parked']));
		$output->writeln('permission backlog: ' . ($s['acl_backlog_seconds'] === null ? 'none' : $s['acl_backlog_seconds'] . ' s'));
		$output->writeln('last reconciliation: ' . ($s['last_reconcile'] === null ? 'never' : $s['last_reconcile']['finished_at']));
		return 0;
	}
}
