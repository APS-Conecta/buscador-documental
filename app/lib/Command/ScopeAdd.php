<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Command;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Scope\FileTree;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ScopeAdd extends Command {
	public function __construct(private ScopeRules $rules, private FileTree $tree, private IRootFolder $root) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('buscador:scope:add')
			->setDescription('Include a folder (and everything below it) or all team folders in the index')
			->addOption('folder-id', null, InputOption::VALUE_REQUIRED, 'File id of the folder')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Folder as <uid>:<path inside that user\'s files>')
			->addOption('groupfolders', null, InputOption::VALUE_NONE, 'All team folders, including future ones');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$chosen = array_filter([$input->getOption('folder-id') !== null, $input->getOption('path') !== null, (bool)$input->getOption('groupfolders')]);
		if (count($chosen) !== 1) {
			$output->writeln('<error>Give exactly one of --folder-id, --path or --groupfolders</error>');
			return 1;
		}
		if ($input->getOption('groupfolders')) {
			$id = $this->rules->addGroupfolders(Actor::Occ);
			$output->writeln("rule $id: all team folders");
			return 0;
		}
		try {
			$folderId = $input->getOption('folder-id') !== null
				? (int)$input->getOption('folder-id')
				: $this->resolve((string)$input->getOption('path'));
		} catch (Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$node = $this->tree->node($folderId);
		if ($node === null) {
			$output->writeln('<error>No such folder</error>');
			return 1;
		}
		$userFiles = $node['path'] === 'files' || str_starts_with($node['path'], 'files/') || $this->tree->storageKind($node['storage']) === 'groupfolder';
		if (!$userFiles || !$this->tree->isDirectory($folderId)) {
			$output->writeln('<error>That id is not a folder among the files (trash, versions and plain files are not scopes)</error>');
			return 1;
		}
		$id = $this->rules->addFolder($folderId, Actor::Occ);
		$output->writeln("rule $id: folder $folderId");
		return 0;
	}

	private function resolve(string $spec): int {
		[$uid, $path] = array_pad(explode(':', $spec, 2), 2, '');
		if ($uid === '' || $path === '') {
			throw new \InvalidArgumentException('--path must look like <uid>:<path>');
		}
		$node = $this->root->getUserFolder($uid)->get($path);
		if (!$node instanceof Folder) {
			throw new \InvalidArgumentException('That path is not a folder');
		}
		return $node->getId();
	}
}
