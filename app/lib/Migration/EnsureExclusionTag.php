<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Migration;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;

/**
 * Without the exclusion tag the check fails closed and nothing is indexed: create it (public and user-assignable,
 * ADR-0013) when the app is enabled. An existing tag in any letter case, whoever made it, is left alone.
 */
class EnsureExclusionTag implements IRepairStep {
	public function __construct(private ISystemTagManager $tags) {
	}

	public function getName(): string {
		return 'Create the rag-excluded system tag';
	}

	public function run(IOutput $output): void {
		foreach ($this->tags->getAllTags(null, ExclusionChecker::TAG_NAME) as $tag) {
			if (ExclusionChecker::isExclusionTagName($tag->getName())) {
				return;
			}
		}
		try {
			$this->tags->createTag(ExclusionChecker::TAG_NAME, true, true);
			$output->info('rag-excluded tag created');
		} catch (TagAlreadyExistsException) {
			// someone created it between the check and now: fine
		}
	}
}
