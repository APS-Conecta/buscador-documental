<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScopeList extends Command {
	public function __construct(private ScopeRules $rules, private Settings $settings) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:scope:list')->setDescription('Show the scope mode and the inclusion rules');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('mode: ' . $this->settings->scopeMode());
		$rules = $this->rules->all();
		if ($rules === []) {
			$output->writeln('rules: none (under opt_in nothing is indexed)');
		}
		foreach ($rules as $r) {
			$output->writeln(sprintf('rule %d: %s', $r['id'], $r['kind'] === 'folder' ? 'folder ' . $r['file_id'] : 'all team folders'));
		}
		return 0;
	}
}
