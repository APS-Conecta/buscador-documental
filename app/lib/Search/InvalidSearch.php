<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use RuntimeException;

/** A search request the caller got wrong. The message is Spanish and safe to show. */
final class InvalidSearch extends RuntimeException {
}
