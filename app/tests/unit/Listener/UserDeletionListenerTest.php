<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Listener;

use OCA\BuscadorDocumental\Index\UserPurger;
use OCA\BuscadorDocumental\Listener\UserDeletionListener;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserDeletionListenerTest extends TestCase {
	private function user(string $uid): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	public function testBeforeDeletionOnlyRecordsAndDeletionErases(): void {
		$purger = $this->createMock(UserPurger::class);
		$purger->expects($this->once())->method('recordPending')->with('alice');
		$purger->expects($this->once())->method('purgePending')->with('alice');
		$l = new UserDeletionListener($purger, $this->createMock(LoggerInterface::class));
		$l->handle(new BeforeUserDeletedEvent($this->user('alice')));          // another listener may still veto the deletion
		$l->handle(new UserDeletedEvent($this->user('alice')));
	}

	public function testAFailureNeverReachesTheDeletion(): void {
		$purger = $this->createMock(UserPurger::class);
		$purger->method('recordPending')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->callback(static fn (string $m): bool => !str_contains($m, 'alice') && str_contains($m, 'RuntimeException')));
		(new UserDeletionListener($purger, $logger))->handle(new BeforeUserDeletedEvent($this->user('alice')));
	}
}
