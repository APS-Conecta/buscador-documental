<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Status\Reindexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Reindex extends Command {
	public function __construct(private Reindexer $reindexer) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:reindex')->setDescription('Queue a file (or every known file) for re-evaluation')
			->addArgument('file-id', InputArgument::OPTIONAL, 'File id')
			->addOption('all', null, InputOption::VALUE_NONE, 'Every file the index knows');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = $input->getArgument('file-id');
		if (($id === null) === !$input->getOption('all')) {
			$output->writeln('<error>Give a file id or --all</error>');
			return 1;
		}
		$n = $input->getOption('all') ? $this->reindexer->enqueueAll() : $this->reindexer->enqueue((int)$id);
		$output->writeln("queued: $n");
		return 0;
	}
}
