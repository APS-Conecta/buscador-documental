<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Status\Prometheus;
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
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output')
			->addOption('prometheus', null, InputOption::VALUE_NONE, 'Prometheus text format (numbers only), for a monitoring agent');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$s = $this->report->snapshot();
		if ($input->getOption('prometheus')) {
			$output->write(Prometheus::render($s));
			return 0;
		}
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
		foreach ($s['vectors']['generations'] as $g) {
			$output->writeln(sprintf('vectors generation %d (%s, %s): coverage %.1f%%', $g['gen'], $g['profile'], $g['state'], $g['coverage'] * 100));
		}
		if ($s['vectors']['generations'] !== []) {
			$output->writeln(sprintf('vectors: %d queued, %d failed', $s['vectors']['queued'], $s['vectors']['failed']));
		}
		foreach ($s['eta'] as $lane => $e) {
			$output->writeln(sprintf('%s lane: %d remaining, %s', $lane, $e['remaining'], match ($e['state']) {
				'ok' => sprintf('about %s at %.0f per hour', self::duration((int)$e['eta_seconds']), $e['rate_per_hour']),
				'done' => 'nothing left',
				'paused' => 'paused',
				'window_closed' => 'night window closed, opens in ' . self::duration((int)$e['opens_in_seconds']),
				default => 'no estimate yet (too few completions in the last hour)',
			}));
		}
		$output->writeln('permission backlog: ' . ($s['acl_backlog_seconds'] === null ? 'none' : $s['acl_backlog_seconds'] . ' s'));
		$output->writeln('last reconciliation: ' . ($s['last_reconcile'] === null ? 'never' : $s['last_reconcile']['finished_at']));
		return 0;
	}

	private static function duration(int $s): string {
		return $s >= 86400 ? sprintf('%.1f days', $s / 86400) : ($s >= 3600 ? sprintf('%.1f hours', $s / 3600) : sprintf('%d minutes', max(1, intdiv($s, 60))));
	}
}
