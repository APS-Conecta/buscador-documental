<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The caller's principal set. `x:recheck` is always present: it is caller-side only (never stored), so files whose
 * access is not modelled in file_acl still reach the live recheck, which decides (ADR-0003).
 */
class PrincipalResolver {
	public const RECHECK = 'x:recheck';

	public function __construct(private IUserManager $users, private IGroupManager $groups) {
	}

	/** @return list<string> */
	public function forUser(string $uid): array {
		$p = ['u:' . $uid, self::RECHECK];
		$user = $this->users->get($uid);
		if ($user !== null) {
			foreach ($this->groups->getUserGroupIds($user) as $gid) {
				$p[] = 'g:' . $gid;
			}
		}
		return array_values(array_unique($p));
	}
}
