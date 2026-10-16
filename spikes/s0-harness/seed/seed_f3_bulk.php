<?php
// F3 bulk: user share with attributes (download disabled) so the share-attribute check has real work.
// u_admin200 shares gf-acl/bulk/shared to u_shared, a user with no groups (so the hit resolves through the share storage,
// not a groupfolder mount). Idempotent: skipped when the share exists.
require_once '/var/www/html/lib/base.php';

use OCP\Constants;
use OCP\Share\IManager;
use OCP\Share\IShare;

$c = \OC::$server;
\OC_Util::setupFS('u_admin200');
$folder = $c->get(\OCP\Files\IRootFolder::class)->getUserFolder('u_admin200')->get('gf-acl/bulk/shared');
$mgr = $c->get(IManager::class);
$um = $c->get(\OCP\IUserManager::class);
if ($um->get('u_shared') === null) {
	$um->createUser('u_shared', 'Bd-spike-u_shared-pw!');
}
foreach ($mgr->getSharesBy('u_admin200', IShare::TYPE_USER, $folder, false, 10) as $s) {
	if ($s->getSharedWith() === 'u_shared') {
		echo "share exists\n";
		exit(0);
	}
}
$share = $mgr->newShare();
$share->setNode($folder)->setShareType(IShare::TYPE_USER)->setSharedWith('u_shared')
	->setSharedBy('u_admin200')->setShareOwner('u_admin200')->setPermissions(Constants::PERMISSION_READ);
$attrs = $share->newAttributes();
$attrs->setAttribute('permissions', 'download', false);
$share->setAttributes($attrs);
$mgr->createShare($share);
echo "share created\n";
