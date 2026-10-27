<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Migration;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * The tag must exist so folder owners can opt a folder out (public and user-assignable, ADR-0013): create it when the app is
 * enabled. An existing tag in any letter case, whoever made it, is left alone.
 */
class EnsureExclusionTag implements IRepairStep {
	public function __construct(private ExclusionChecker $exclusion) {
	}

	public function getName(): string {
		return 'Create the rag-excluded system tag';
	}

	public function run(IOutput $output): void {
		if ($this->exclusion->ensureTag()) {
			$output->info('rag-excluded tag created');
		}
	}
}
