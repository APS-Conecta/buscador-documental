<?php
// m9: the live recheck for 50 search hits, inside Nextcloud, one fresh PHP process per call (like a request).
// Usage: php m9_isreadable.php <uid> <variant: naive|batched> <hits.json>
// Per hit: getById through the user's folder (applies mounts + groupfolder ACL), isReadable(), the `rag-excluded` tag on
// every ancestor up to the mount root, and the download attribute of a user share. naive = per hit; batched = per
// request caches per parent folder (readable? excluded?), the "cached per (uid, batch)" variant of 02-performance.
// Prints one JSON line: setup_ms (FS setup for the user's mounts), check_ms, counts.
require_once '/var/www/html/lib/base.php';

[, $uid, $variant, $hitsFile] = $argv;
$hits = json_decode(file_get_contents($hitsFile), true);
$c = \OC::$server;

$t0 = hrtime(true);
\OC_Util::tearDownFS();
\OC_Util::setupFS($uid);
$userFolder = $c->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$mapper = $c->get(\OCP\SystemTag\ISystemTagObjectMapper::class);
$tagId = $c->get(\OCP\SystemTag\ISystemTagManager::class)->getTag('rag-excluded', true, true)->getId();
$setup = (hrtime(true) - $t0) / 1e6;

$t1 = hrtime(true);
$ok = $denied = $excluded = $shareBlocked = 0;
$ancestorCache = [];                       // batched: folderId => "this folder or any ancestor carries the tag"
$chainExcluded = function (\OCP\Files\Node $p) use (&$chainExcluded, $mapper, $tagId, $variant, &$ancestorCache): bool {
	$id = (string)$p->getId();
	if ($variant === 'batched' && isset($ancestorCache[$id])) {
		return $ancestorCache[$id];
	}
	$parent = $p->getParent();
	$atRoot = $parent === null || $p->getPath() === $parent->getPath() || $parent->getPath() === '/';
	$excl = $mapper->haveTag([$id], 'files', $tagId) || (!$atRoot && $chainExcluded($parent));
	if ($variant === 'batched') {
		$ancestorCache[$id] = $excl;
	}
	return $excl;
};
$walk = fn (\OCP\Files\Node $n): bool => $chainExcluded($n->getParent());
foreach ($hits as $h) {
	$nodes = $userFolder->getById($h['file_id']);
	if (!$nodes || !$nodes[0]->isReadable()) {
		$denied++;
		continue;
	}
	$n = $nodes[0];
	if ($walk($n)) {
		$excluded++;
		continue;
	}
	$storage = $n->getStorage();
	if ($storage->instanceOfStorage(\OCA\Files_Sharing\ISharedStorage::class)) {   // storages are wrapped (trashbin, ...)
		$attrs = $storage->getShare()->getAttributes();
		if ($attrs !== null && $attrs->getAttribute('permissions', 'download') === false) {
			$shareBlocked++;               // metadata recorded; search still lists it (download policy is the UI's job)
		}
	}
	$ok++;
}
$check = (hrtime(true) - $t1) / 1e6;
echo json_encode(['uid' => $uid, 'variant' => $variant, 'setup_ms' => round($setup, 1), 'check_ms' => round($check, 1),
	'total_ms' => round($setup + $check, 1), 'ok' => $ok, 'denied' => $denied, 'excluded' => $excluded, 'share_download_off' => $shareBlocked]), "\n";
