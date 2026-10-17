<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Index\UserPurger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PurgeUser extends Command {
	public function __construct(private UserPurger $purger) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:purge-user')
			->setDescription('Erase everything indexed from a user\'s own storage (the user and their files are not touched)')
			->addArgument('uid', InputArgument::OPTIONAL, 'User id of an existing user')
			->addOption('storage-id', null, InputOption::VALUE_REQUIRED, 'Numeric storage id, for a user who is already deleted');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = $input->getArgument('uid');
		$storage = $input->getOption('storage-id');
		if (($uid === null) === ($storage === null)) {
			$output->writeln('<error>Give a user id or --storage-id</error>');
			return 1;
		}
		$n = $storage !== null ? $this->purger->purgeStorageAudited((int)$storage) : $this->purger->purgeUser((string)$uid);
		$output->writeln("erased: $n");
		$output->writeln('Files that are still in scope will be read again at the next reconciliation: remove the rule or tag them rag-excluded to keep them out.');
		return 0;
	}
}
