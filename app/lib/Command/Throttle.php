<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Ops\Throttle as ThrottleSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** How many files one worker reads (text lane) and embeds (vector lane) at the same time. */
class Throttle extends Command {
	public function __construct(private ThrottleSettings $throttle) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:throttle')
			->setDescription('Concurrency caps per worker name (and 4x / 2x that across all workers): show, set --text=N --vec=N, or reset')
			->addArgument('action', InputArgument::OPTIONAL, 'show (default), set or reset', 'show')
			->addOption('text', null, InputOption::VALUE_REQUIRED, 'set: files read at once, 1 to 16 (default 4)')
			->addOption('vec', null, InputOption::VALUE_REQUIRED, 'set: files embedded at once, 1 to 16 (default 2)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			switch ((string)$input->getArgument('action')) {
				case 'show':
					break;
				case 'set':
					$t = $input->getOption('text');
					$v = $input->getOption('vec');
					if ($t === null && $v === null) {
						$output->writeln('<error>set needs --text=N and/or --vec=N</error>');
						return 1;
					}
					foreach ([$t, $v] as $x) {
						if ($x !== null && !preg_match('/^-?\d+$/', (string)$x)) {
							$output->writeln('<error>The caps must be whole numbers from 1 to 16</error>');
							return 1;
						}
					}
					$this->throttle->set($t === null ? null : (int)$t, $v === null ? null : (int)$v);
					break;
				case 'reset':
					$this->throttle->reset();
					break;
				default:
					$output->writeln('<error>Use show, set or reset</error>');
					return 1;
			}
		} catch (OpsException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$s = $this->throttle->show();
		$output->writeln(sprintf('text lane: %d at once per worker; vector lane: %d at once per worker', $s['text'], $s['vec']));
		return 0;
	}
}
