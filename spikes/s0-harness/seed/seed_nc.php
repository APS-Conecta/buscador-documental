<?php
// Throwaway seed for the S0 spike: one PHP process creates groups, users, memberships and
// groupfolders (an occ call per object would boot Nextcloud ~700 times). Idempotent:
// every create is query-before-create. Deterministic: names derive from loop indexes and a
// fixed mt_srand seed. Run as www-data: php seed_nc.php
require_once '/var/www/html/lib/base.php';

use OCA\GroupFolders\Folder\FolderManager;

const N_GROUPS = 200;
const N_FOLDERS = 100;   // each shared with 2 groups -> F1 (1 group) reads 1 % of them
const N_FILLER = 200;

$c = \OC::$server;
$groups = $c->get(\OCP\IGroupManager::class);
$users = $c->get(\OCP\IUserManager::class);
$fm = $c->get(FolderManager::class);
mt_srand(42);

$gid = static fn (int $i): string => sprintf('g%03d', $i);
$ensureGroup = static function (string $id) use ($groups) {
	return $groups->get($id) ?? $groups->createGroup($id);
};
$ensureUser = static function (string $id) use ($users) {
	return $users->get($id) ?? $users->createUser($id, 'Bd-spike-' . $id . '-pw!');
};

for ($i = 1; $i <= N_GROUPS; $i++) {
	$ensureGroup($gid($i));
}

// F1: narrow user, one group.
$ensureGroup($gid(1))->addUser($ensureUser('u_narrow'));
// F2: admin-like user in every group.
$f2 = $ensureUser('u_admin200');
for ($i = 1; $i <= N_GROUPS; $i++) {
	$ensureGroup($gid($i))->addUser($f2);
}
// Filler users, 1-3 groups each (realistic group sizes for file_acl.principals).
for ($i = 1; $i <= N_FILLER; $i++) {
	$u = $ensureUser(sprintf('u%04d', $i));
	for ($k = mt_rand(1, 3); $k > 0; $k--) {
		$groups->get($gid(mt_rand(1, N_GROUPS)))->addUser($u);
	}
}

$existing = [];
foreach ($fm->getAllFolders() as $f) {
	$existing[$f->mountPoint] = $f->id;
}
// addApplicableGroup is not idempotent (unique key); a repeat is a no-op here.
$addGroup = static function (int $id, string $g) use ($fm): void {
	try {
		$fm->addApplicableGroup($id, $g);
	} catch (\OCP\DB\Exception $e) {
		if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			throw $e;
		}
	}
};
$ids = [];
for ($i = 1; $i <= N_FOLDERS; $i++) {
	$name = sprintf('gf%03d', $i);
	$id = $existing[$name] ?? $fm->createFolder($name);
	$ids[$name] = $id;
	foreach ([$gid(2 * $i - 1), $gid(2 * $i)] as $g) {
		$addGroup($id, $g);
	}
}
// F3: ACL tree folder shared with g001 (F1 and F2 are both members).
$f3 = $existing['gf-acl'] ?? $fm->createFolder('gf-acl');
$addGroup($f3, $gid(1));
$ids['gf-acl'] = $f3;

file_put_contents('php://stdout', json_encode(['folders' => $ids]) . "\n");
