<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Canary control, run inside the Nextcloud container as www-data, ONE PROCESS PER CALL (a recheck must never reuse
 * the process that changed the share: the mount cache is refreshed per request, see S1 phase 3 notes).
 *   php ctl.php setup
 *   php ctl.php share <owner> <path> <user|group> <with> [nodownload]
 *   php ctl.php unshare <owner> <path> <with>
 *   php ctl.php group <add|remove> <gid> <uid>
 *   php ctl.php tag|untag <uid> <path>
 *   php ctl.php acl <fileId>             write file_acl for the file (the S3 listener is a stub)
 *   php ctl.php erase <fileId> <reason>
 *   php ctl.php search <uid> <query>     prints {"status","message","hits","dropped"}
 */

use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCA\BuscadorDocumental\Index\IndexEraser;
use OCA\BuscadorDocumental\Search\Recheck;
use OCA\BuscadorDocumental\Search\Retrieve;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;

require_once '/var/www/html/lib/base.php';

$cmd = $argv[1] ?? '';
$node = static fn (string $uid, string $path) => Server::get(IRootFolder::class)->getUserFolder($uid)->get($path);
$tagId = static function (): string {
	foreach (Server::get(ISystemTagManager::class)->getAllTags(null, 'rag-excluded') as $t) {
		return $t->getId();
	}
	throw new RuntimeException('tag rag-excluded missing; run setup');
};

switch ($cmd) {
	case 'setup':
		$users = Server::get(IUserManager::class);
		foreach (['canary_a', 'canary_b', 'canary_c'] as $u) {
			if (!$users->userExists($u)) {
				$users->createUser($u, 'Canary-pass-123456');
			}
		}
		$groups = Server::get(IGroupManager::class);
		$groups->get('canary_team') ?? $groups->createGroup('canary_team');
		try {
			Server::get(ISystemTagManager::class)->createTag('rag-excluded', false, false); // restricted: invisible, admin-assignable
		} catch (TagAlreadyExistsException) {
		}
		break;

	case 'share':
		[, , $owner, $path, $type, $with] = $argv;
		$s = Server::get(IManager::class)->newShare();
		$s->setNode($node($owner, $path))
			->setShareType($type === 'group' ? IShare::TYPE_GROUP : IShare::TYPE_USER)
			->setSharedWith($with)->setSharedBy($owner)->setShareOwner($owner)->setPermissions(1);
		if (($argv[6] ?? '') === 'nodownload') {
			$a = $s->newAttributes();
			$a->setAttribute('permissions', 'download', false);
			$s->setAttributes($a);
		}
		Server::get(IManager::class)->createShare($s);
		break;

	case 'unshare':
		[, , $owner, $path, $with] = $argv;
		$mgr = Server::get(IManager::class);
		$n = $node($owner, $path);
		foreach ([IShare::TYPE_USER, IShare::TYPE_GROUP] as $type) {
			foreach ($mgr->getSharesBy($owner, $type, $n, false, 50) as $s) {
				if ($s->getSharedWith() === $with) {
					$mgr->deleteShare($s);
				}
			}
		}
		break;

	case 'group':
		[, , $op, $gid, $uid] = $argv;
		$g = Server::get(IGroupManager::class)->get($gid);
		$u = Server::get(IUserManager::class)->get($uid);
		$op === 'add' ? $g->addUser($u) : $g->removeUser($u);
		break;

	case 'tag':
	case 'untag':
		[, , $uid, $path] = $argv;
		$mapper = Server::get(ISystemTagObjectMapper::class);
		$id = (string)$node($uid, $path)->getId();
		$cmd === 'tag' ? $mapper->assignTags($id, 'files', [$tagId()]) : $mapper->unassignTags($id, 'files', [$tagId()]);
		break;

	case 'acl':
		echo Server::get(PrincipalWriter::class)->write((int)$argv[2]) ? "written\n" : "not written\n";
		break;

	case 'erase':
		Server::get(IndexEraser::class)->erase((int)$argv[2], $argv[3] ?? 'excluded');
		break;

	case 'search':
		$out = Server::get(Retrieve::class)->run($argv[2], $argv[3], 10);
		$out['dropped'] = Server::get(Recheck::class)->dropped();
		echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
		break;

	default:
		fwrite(STDERR, "unknown command\n");
		exit(2);
}
