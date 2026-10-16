<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Security;

use RuntimeException;

/** Authentication of the worker channel failed. `reason` is a short code for logs; it never reaches the client. */
class WorkerAuthException extends RuntimeException {
	public function __construct(public readonly string $reason) {
		parent::__construct($reason);
	}
}
