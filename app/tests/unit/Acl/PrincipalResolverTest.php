<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Acl;

use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class PrincipalResolverTest extends TestCase {
	public function testUserGroupsAndRecheckMarker(): void {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('bob')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->with($user)->willReturn(['med', 'enf']);
		$p = (new PrincipalResolver($users, $groups))->forUser('bob');
		$this->assertEqualsCanonicalizing(['u:bob', 'g:med', 'g:enf', 'x:recheck'], $p);
	}

	public function testUnknownUserStillCarriesUidAndRecheck(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$p = (new PrincipalResolver($users, $this->createMock(IGroupManager::class)))->forUser('ghost');
		$this->assertEqualsCanonicalizing(['u:ghost', 'x:recheck'], $p);
	}
}
