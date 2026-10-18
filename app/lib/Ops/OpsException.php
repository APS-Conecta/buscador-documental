<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ops;

use RuntimeException;

/** An operations command the administrator got wrong; the message says how to fix it and is safe to print. */
final class OpsException extends RuntimeException {
}
