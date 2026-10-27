<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Audit;

/**
 * Actors that are not people: background jobs and the command line. They are stored by their value, never mapped to a
 * pseudonym; neither value is 32 hex characters, so they cannot collide with the tokens of users. A uid is always a
 * person, even one named `system` or `occ`.
 */
enum Actor: string {
	case System = 'system';
	case Occ = 'occ';
}
