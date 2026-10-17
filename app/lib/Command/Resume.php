<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Scope\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Resume extends Command {
	public function __construct(private Settings $settings, private AuditLog $audit) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:resume')->setDescription('Resume reading of documents');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->settings->setPaused(false);
		$this->audit->record('occ', 'resume', null, ['paused' => false]);
		$output->writeln('resumed');
		return 0;
	}
}
