<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCP\Files\Node;

/** A hit that survived the live recheck. `snippetAllowed` is false when the best readable node forbids download. */
final class RecheckResult {
	public function __construct(
		public readonly int $fileId,
		public readonly Node $node,
		public readonly bool $snippetAllowed,
	) {
	}
}
