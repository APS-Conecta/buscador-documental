<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Worker;

use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Throwable;

/** Finds a file node by id for the worker channel, through any user's mount that holds it (system-level read). */
class NodeLocator {
	public function __construct(private IUserMountCache $mounts, private IRootFolder $root) {
	}

	public function locate(int $fileId): ?File {
		try {
			$mounts = $this->mounts->getMountsForFileId($fileId);
		} catch (Throwable) {
			return null;
		}
		foreach ($mounts as $mount) {
			try {
				$node = $this->root->getUserFolder($mount->getUser()->getUID())->getFirstNodeById($fileId);
			} catch (Throwable) {
				continue; // a deleted user or a broken storage must not hide the other mounts
			}
			if ($node instanceof File) {
				return $node;
			}
		}
		return null;
	}
}
