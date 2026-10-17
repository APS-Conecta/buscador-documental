<?php
// S1 extension of the S0 F3 fixture. Run inside the bd-s1 Nextcloud container as www-data, after seed_nc.php (S0, reused
// unchanged) has created groups, users and the gf-acl groupfolder, and the bulk files exist and are scanned.
//   share 1: u_admin200 -> u_shared   gf-acl/bulk/shared   download disabled (S0 fixture)
//   share 2: u_admin200 -> u_shared   gf-acl/bulk/expired   expiry in the past (set in oc_share: the API refuses past dates)
//   share 3: u_admin200 -> u_narrow   gf-acl/bulk/multi     download disabled: u_narrow also reaches it through the
//            groupfolder, so the file has two mounts for that user (multi-mount)
// Idempotent: a share that already exists is left alone.
require_once '/var/www/html/lib/base.php';

use OCP\Constants;
use OCP\Share\IManager;
use OCP\Share\IShare;

$c = \OC::$server;
\OC_Util::setupFS('u_admin200');
$root = $c->get(\OCP\Files\IRootFolder::class)->getUserFolder('u_admin200');
$mgr = $c->get(IManager::class);
$um = $c->get(\OCP\IUserManager::class);
$db = $c->get(\OCP\IDBConnection::class);
if ($um->get('u_shared') === null) {
	$um->createUser('u_shared', 'Bd-spike-u_shared-pw!');
}

$share = static function (string $path, string $with, bool $download) use ($root, $mgr): ?IShare {
	$folder = $root->get($path);
	foreach ($mgr->getSharesBy('u_admin200', IShare::TYPE_USER, $folder, false, 10) as $s) {
		if ($s->getSharedWith() === $with) {
			echo "$path -> $with exists\n";
			return null;
		}
	}
	$s = $mgr->newShare();
	$s->setNode($folder)->setShareType(IShare::TYPE_USER)->setSharedWith($with)
		->setSharedBy('u_admin200')->setShareOwner('u_admin200')->setPermissions(Constants::PERMISSION_READ);
	if (!$download) {
		$a = $s->newAttributes();
		$a->setAttribute('permissions', 'download', false);
		$s->setAttributes($a);
	}
	$created = $mgr->createShare($s);
	echo "$path -> $with created\n";
	return $created;
};

$share('gf-acl/bulk/shared', 'u_shared', false);
$expired = $share('gf-acl/bulk/expired', 'u_shared', true);
if ($expired !== null) {
	$id = (int)substr($expired->getId(), strrpos($expired->getId(), ':') !== false ? strrpos($expired->getId(), ':') + 1 : 0);
	$db->executeStatement('UPDATE *PREFIX*share SET expiration = ? WHERE id = ?', ['2020-01-01 00:00:00', $id]);
}
$share('gf-acl/bulk/multi', 'u_narrow', false);
