<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Scope\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Stops new reading and byte serving. Removals, exclusion and erasure always continue (ADR-0013). */
class Pause extends Command {
	public function __construct(private Settings $settings, private AuditLog $audit) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:pause')->setDescription('Pause reading of documents (removals and exclusions continue)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->settings->setPaused(true);
		$this->audit->record('occ', 'pause', null, ['paused' => true]);
		$output->writeln('paused');
		return 0;
	}
}
