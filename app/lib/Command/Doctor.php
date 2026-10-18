<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Ops\Doctor as Checks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Doctor extends Command {
	public function __construct(private Checks $checks) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:doctor')
			->setDescription('Health checks: ok, warn or fail with the reason (exit code 1 on a failure)')
			->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$results = $this->checks->run();
		if ($input->getOption('json')) {
			$output->writeln(json_encode($results, JSON_THROW_ON_ERROR));
		} else {
			foreach ($results as $c) {
				$tag = match ($c['status']) { 'ok' => 'ok  ', 'warn' => '<comment>warn</comment>', default => '<error>FAIL</error>' };
				$output->writeln(sprintf('%s %s: %s', $tag, $c['id'], $c['detail']));
			}
		}
		return Checks::exitCode($results);
	}
}
