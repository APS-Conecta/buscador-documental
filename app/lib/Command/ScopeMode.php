<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use InvalidArgumentException;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Scope\ScopeLocked;
use OCA\BuscadorDocumental\Scope\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScopeMode extends Command {
	public function __construct(private Settings $settings, private AuditLog $audit) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:scope:mode')->setDescription('Set the scope mode: opt_in (default) or all_readable (needs counsel confirmation and a DPIA)')
			->addArgument('mode', InputArgument::REQUIRED, 'opt_in or all_readable');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mode = (string)$input->getArgument('mode');
		try {
			$this->settings->setScopeMode($mode);
		} catch (ScopeLocked | InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$this->audit->record('occ', 'scope.mode', null, ['mode' => $mode]);
		$output->writeln("mode: $mode");
		return 0;
	}
}
