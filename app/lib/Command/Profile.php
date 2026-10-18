<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Search\ProfileCatalog;
use OCA\BuscadorDocumental\Search\ProfileException;
use OCA\BuscadorDocumental\Search\ProfileManager;
use OCA\BuscadorDocumental\Search\VecBackfill;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** The embedding profile, in one command. Changing the profile is always an administrator's act: nothing switches by itself. */
class Profile extends Command {
	private const ACTIONS = ['show', 'list', 'set', 'activate', 'drop', 'semantic', 'refresh'];

	public function __construct(private ProfileManager $manager, private ProfileCatalog $catalog, private VecBackfill $backfill) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:profile')
			->setDescription('Embedding profiles for search by meaning: show, list, set, activate, drop, semantic, refresh')
			->addArgument('action', InputArgument::REQUIRED, implode(' | ', self::ACTIONS))
			->addArgument('value', InputArgument::OPTIONAL, 'set: profile id; activate and drop: generation number; semantic: on or off')
			->addOption('url', null, InputOption::VALUE_REQUIRED, 'set: address of the Ollama endpoint (http or https)')
			->addOption('token-file', null, InputOption::VALUE_REQUIRED, 'set: file holding the endpoint token ("-" clears the stored one)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		$value = $input->getArgument('value');
		try {
			switch ($action) {
				case 'list':
					foreach ($this->catalog->all() as $p) {
						$output->writeln(sprintf('%s: model %s, %d dimensions', $p['id'], $p['model'], $p['dim']));
					}
					return 0;
				case 'show':
					return $this->show($output);
				case 'refresh':
					$r = $this->backfill->tick(1000);
					$output->writeln(sprintf('queued %d files; generations made ready: %s', $r['queued'], $r['ready'] === [] ? 'none' : implode(', ', $r['ready'])));
					foreach ($r['coverage'] as $gen => $c) {
						$output->writeln(sprintf('generation %d: coverage %.1f%%', $gen, $c * 100));
					}
					return 0;
				case 'set':
					return $this->set($input, $output, $value);
				case 'activate':
				case 'drop':
					if ($value === null || !ctype_digit($value)) {
						$output->writeln("<error>$action needs a generation number</error>");
						return 1;
					}
					$action === 'activate' ? $this->manager->activate((int)$value) : $this->manager->drop((int)$value);
					$output->writeln("$action: generation $value");
					return 0;
				case 'semantic':
					if (!in_array($value, ['on', 'off'], true)) {
						$output->writeln('<error>semantic needs on or off</error>');
						return 1;
					}
					$this->manager->semantic($value === 'on');
					$output->writeln("semantic modes: $value");
					return 0;
				default:
					$output->writeln('<error>Unknown action. Use one of: ' . implode(', ', self::ACTIONS) . '</error>');
					return 1;
			}
		} catch (ProfileException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
	}

	private function set(InputInterface $input, OutputInterface $output, mixed $profileId): int {
		$url = $input->getOption('url');
		if (!is_string($profileId) || !is_string($url)) {
			$output->writeln('<error>set needs a profile id and --url=http://host:11434</error>');
			return 1;
		}
		$token = null;
		$file = $input->getOption('token-file');
		if (is_string($file)) {
			$raw = $file === '-' ? '' : @file_get_contents($file);
			if ($raw === false) {
				$output->writeln('<error>Cannot read the token file</error>');
				return 1;
			}
			$token = trim($raw);
		}
		$r = $this->manager->set($profileId, $url, $token);
		$output->writeln(sprintf('generation %d: %s (%d dimensions) set up; vectors are computed in the background and the generation becomes ready when coverage is high enough', $r['gen'], $r['model'], $r['dim']));
		$output->writeln('then: occ buscador:profile activate ' . $r['gen']);
		return 0;
	}

	private function show(OutputInterface $output): int {
		$s = $this->manager->show();
		$output->writeln('endpoint configured: ' . ($s['endpoint_configured'] ? 'yes' : 'no'));
		$output->writeln('semantic modes: ' . ($s['semantic_enabled'] ? 'on' : 'off'));
		$output->writeln(sprintf('minimum coverage: %.0f%%', $s['min_coverage'] * 100));
		foreach ($s['generations'] as $g) {
			$output->writeln(sprintf('generation %d: %s, %s, %d dimensions, %s, coverage %.1f%%', $g['gen'], $g['profile'], $g['model'], $g['dim'], $g['state'], $g['coverage'] * 100));
		}
		if ($s['generations'] === []) {
			$output->writeln('no generations yet: occ buscador:profile set <profile> --url=...');
		}
		return 0;
	}
}
