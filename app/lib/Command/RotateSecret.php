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
		if (!is_string($grace) || preg_match('/^\d{1,3}$/D', $grace) !== 1 || (int)$grace > 720) {
			$output->writeln('<error>The grace period is a whole number of hours from 0 to 720.</error>');
			return 1;
		}
		$file = $input->getOption('to-file');
		$file = is_string($file) && $file !== '' ? $file : null;
		$temp = null;
		if ($file !== null) {
			if (is_link($file)) {
				$output->writeln('<error>The file is a symbolic link: refusing to write a secret through it. Nothing was changed.</error>');
				return 1;
			}
			// Create the private temporary file first: if there is nowhere to put the secret, nothing is rotated.
			$temp = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
			$old = umask(0o177);
			$handle = @fopen($temp, 'x');
			umask($old);
			if ($handle === false) {
				$output->writeln('<error>Cannot create a file there, nothing was changed.</error>');
				return 1;
			}
			fclose($handle);
		}
		$notice = $this->rotation->previousStillValid();
		try {
			$secret = $this->rotation->rotate((int)$grace);
		} catch (OpsException $e) {
			if ($temp !== null) {
				@unlink($temp);
			}
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if ($notice) {
			$output->writeln('<comment>The secret that was still valid from the last rotation stops working now; only the one it replaced has a grace period.</comment>');
		}
		if ($temp !== null && $file !== null && @file_put_contents($temp, $secret . "\n") !== false && @rename($temp, $file)) {
			$output->writeln("new secret written to $file; set it as BD_WORKER_SECRET for the worker and restart it within $grace hours");
			return 0;
		}
		if ($temp !== null) {
			@unlink($temp);
			$output->writeln('<error>The secret was rotated but could not be written to the file; it is printed once here instead.</error>');
		}
		$output->writeln($secret);
		$output->writeln("<comment>This is the new BD_WORKER_SECRET; it is not shown again. The old secret works for $grace hours.</comment>");
		return 0;
	}
}
