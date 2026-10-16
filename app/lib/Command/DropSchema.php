<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Migration\Teardown;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DropSchema extends Command {
	public function __construct(private Teardown $teardown) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador_documental:schema:drop')
			->setDescription('Drop the aps_search schema (refuses while the index holds rows)')
			->addOption('force', null, InputOption::VALUE_NONE, 'Drop even when file_state has rows');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->teardown->run((bool)$input->getOption('force'));
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('aps_search dropped');
		return 0;
	}
}
