<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use RuntimeException;

/** The embedding endpoint could not be used for setting up a profile. `reason` is a code: not_configured, unreachable, model_not_found, bad_answer. */
final class ProbeFailed extends RuntimeException {
	public function __construct(public readonly string $reason) {
		parent::__construct($reason);
	}
}
