<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Listener;

use OCA\BuscadorDocumental\Index\UserPurger;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Before the deletion (which another listener may still veto) only the storage is remembered; the erasure runs once the
 * user is really gone. A failure here never blocks the deletion; the nightly reconciliation erases any residue.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletionListener implements IEventListener {
	public function __construct(private UserPurger $purger, private LoggerInterface $logger) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeUserDeletedEvent) {
				$this->purger->recordPending($event->getUser()->getUID());
			} elseif ($event instanceof UserDeletedEvent) {
				$this->purger->purgePending($event->getUser()->getUID());
			}
		} catch (Throwable $e) {
			$this->logger->warning('buscador_documental: user erasure step failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}
}
