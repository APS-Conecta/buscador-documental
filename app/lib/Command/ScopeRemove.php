<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScopeRemove extends Command {
	public function __construct(private ScopeRules $rules) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:scope:remove')->setDescription('Remove an inclusion rule (what it covered is removed from the index at the next evaluation)')
			->addArgument('id', InputArgument::REQUIRED, 'Rule id from buscador:scope:list');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->rules->remove((int)$input->getArgument('id'), Actor::Occ)) {
			$output->writeln('<error>No such rule</error>');
			return 1;
		}
		$output->writeln('rule removed');
		return 0;
	}
}
