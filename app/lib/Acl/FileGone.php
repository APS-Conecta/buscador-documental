<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

/** The file itself has no filecache row (deleted or never existed), as opposed to a broken ancestor chain. */
class FileGone extends ExclusionUnavailable {
}
