<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Scope;

use RuntimeException;

/** `all_readable` was requested without the legal gate (ADR-0006). */
class ScopeLocked extends RuntimeException {
}
