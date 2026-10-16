<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Test setup, run inside the Nextcloud container as www-data:
 *   php custom_apps/buscador_documental/tests/canary/enqueue.php ensure-tag
 *   php custom_apps/buscador_documental/tests/canary/enqueue.php enqueue <fileId>
 * `ensure-tag` creates the restricted (admin-assignable, invisible) system tag `rag-excluded` the exclusion check needs.
 */

use OCA\BuscadorDocumental\Index\FileIndexer;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;

require_once '/var/www/html/lib/base.php';

$cmd = $argv[1] ?? '';
if ($cmd === 'ensure-tag') {
	$tags = \OCP\Server::get(ISystemTagManager::class);
	try {
		$tags->createTag('rag-excluded', false, false);
		echo "tag created\n";
	} catch (TagAlreadyExistsException) {
		echo "tag exists\n";
	}
} elseif ($cmd === 'enqueue' && isset($argv[2])) {
	$ok = \OCP\Server::get(FileIndexer::class)->enqueue((int)$argv[2]);
	echo $ok ? "enqueued\n" : "refused\n";
	exit($ok ? 0 : 1);
} else {
	fwrite(STDERR, "usage: enqueue.php ensure-tag | enqueue <fileId>\n");
	exit(2);
}
