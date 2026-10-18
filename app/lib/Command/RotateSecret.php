<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Security\SecretRotation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RotateSecret extends Command {
	public function __construct(private SecretRotation $rotation) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:rotate-secret')
			->setDescription('New worker secret now; the old one stays valid for the grace period so the worker can be updated')
			->addOption('to-file', null, InputOption::VALUE_REQUIRED, 'Write the new secret to this file (mode 0600) instead of printing it')
			->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Hours the old secret stays valid (0 to 720)', '24');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$grace = $input->getOption('grace');
		if (!is_string($grace) || !preg_match('/^-?\d+$/', $grace)) {
			$output->writeln('<error>The grace period is a whole number of hours from 0 to 720.</error>');
			return 1;
		}
		$file = $input->getOption('to-file');
		if (is_string($file) && $file !== '') {
			$old = umask(0o177);                                    // the file must never be readable by others, even for an instant
			$probe = @file_put_contents($file, '');
			umask($old);
			if ($probe === false) {
				$output->writeln('<error>Cannot write the file, nothing was changed.</error>');
				return 1;
			}
			chmod($file, 0o600);
		}
		try {
			$secret = $this->rotation->rotate((int)$grace);
		} catch (OpsException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if (is_string($file) && $file !== '') {
			file_put_contents($file, $secret . "\n");
			$output->writeln("new secret written to $file; set it as BD_WORKER_SECRET for the worker and restart it within $grace hours");
		} else {
			$output->writeln($secret);
			$output->writeln("<comment>This is the new BD_WORKER_SECRET; it is not shown again. The old secret works for $grace hours.</comment>");
		}
		return 0;
	}
}
