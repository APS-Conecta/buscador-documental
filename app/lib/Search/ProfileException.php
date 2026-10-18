<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use RuntimeException;

/** A profile command that cannot proceed; the message tells the administrator what to do and is safe to print. */
final class ProfileException extends RuntimeException {
}
