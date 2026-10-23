<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Security\SecretRotation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ buscador:adopt-secret`: take the worker secret the suite generated from BD_WORKER_SECRET, instead of
 * making one with buscador:rotate-secret. The suite runs this once at install time; a repeated run is a no-op.
 */
class AdoptSecret extends Command {
	public function __construct(private SecretRotation $rotation) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:adopt-secret')
			->setDescription('Take the worker secret from BD_WORKER_SECRET in the environment (the suite runs this at install time)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$secret = getenv('BD_WORKER_SECRET');
		if (!is_string($secret) || $secret === '') {
			$output->writeln('<error>BD_WORKER_SECRET is not set in the environment; nothing was changed.</error>');
			return 1;
		}
		try {
			$adopted = $this->rotation->adopt($secret);
		} catch (OpsException $e) {
			$output->writeln('<error>' . $e->getMessage() . ' Nothing was changed.</error>');
			return 1;
		}
		$output->writeln($adopted
			? 'Worker secret adopted from BD_WORKER_SECRET; restart the worker so it signs with the same secret.'
			: 'BD_WORKER_SECRET is already the worker secret; nothing was changed.');
		return 0;
	}
}
