<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

use RuntimeException;

/** The exclusion state of a file could not be established; callers must fail closed. */
class ExclusionUnavailable extends RuntimeException {
}
