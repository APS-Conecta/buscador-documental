<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use DateTimeImmutable;
use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Ops\WorkWindow;
use OCP\IDateTimeZone;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** The night window for heavy work: by default only the vector lane waits for it; new and edited files keep being read by day. */
class Window extends Command {
	public function __construct(private WorkWindow $window, private IDateTimeZone $zones) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:window')
			->setDescription('Night window for heavy work: show, set HH:MM-HH:MM, or off')
			->addArgument('action', InputArgument::OPTIONAL, 'show (default), set or off', 'show')
			->addArgument('window', InputArgument::OPTIONAL, 'set: HH:MM-HH:MM, may cross midnight')
			->addOption('lanes', null, InputOption::VALUE_REQUIRED, 'set: vec (default) or all', 'vec')
			->addOption('tz', null, InputOption::VALUE_REQUIRED, 'set: IANA time zone (default: the server\'s Nextcloud time zone)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			switch ((string)$input->getArgument('action')) {
				case 'show':
					$s = $this->window->show(new DateTimeImmutable('now'));
					$output->writeln($s['window'] === null
						? 'window: none (heavy work runs at any hour)'
						: sprintf('window: %s (%s), lanes: %s, %s now', $s['window'], $s['tz'], $s['lanes'], $s['open_now'] ? 'open' : 'closed'));
					return 0;
				case 'set':
					$w = $input->getArgument('window');
					if (!is_string($w)) {
						$output->writeln('<error>set needs a window such as 01:00-05:00</error>');
						return 1;
					}
					$tz = $input->getOption('tz');
					$this->window->set($w, (string)$input->getOption('lanes'), is_string($tz) ? $tz : $this->zones->getDefaultTimeZone()->getName());
					$output->writeln('window set');
					return 0;
				case 'off':
					$this->window->off();
					$output->writeln('window removed');
					return 0;
				default:
					$output->writeln('<error>Use show, set or off</error>');
					return 1;
			}
		} catch (OpsException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
	}
}
