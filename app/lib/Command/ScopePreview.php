<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\ScopeSql;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScopePreview extends Command {
	public function __construct(private ScopeRules $rules, private IndexDb $db) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:scope:preview')->setDescription('Count what the current rules would index (numbers only, no names)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$set = $this->rules->current();
		$p = ScopeSql::preview($this->db, $set);
		$output->writeln(sprintf('mode: %s', $set->mode));
		$output->writeln(sprintf('files: %d', $p['files']));
		$output->writeln(sprintf('size: %.1f MiB', $p['bytes'] / 1048576));
		return 0;
	}
}
