<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'buscador_documental';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService(\OCA\BuscadorDocumental\Search\Glossary::class, static fn () => \OCA\BuscadorDocumental\Search\Glossary::fromFile(__DIR__ . '/../Search/glossary.json'));
		$context->registerService(\OCA\BuscadorDocumental\Search\Cursor::class, static fn ($c) => new \OCA\BuscadorDocumental\Search\Cursor($c->get(\OCP\IConfig::class)->getSystemValueString('secret')));
		foreach ([
			\OCP\Files\Cache\CacheEntryInsertedEvent::class, \OCP\Files\Cache\CacheEntryUpdatedEvent::class,
			\OCP\Files\Cache\CacheEntryRemovedEvent::class, \OCP\Files\Cache\CacheEntriesRemovedEvent::class,
			\OCP\Share\Events\ShareCreatedEvent::class, \OCP\Share\Events\ShareDeletedEvent::class,
			\OCP\SystemTag\TagAssignedEvent::class, \OCP\SystemTag\TagUnassignedEvent::class,
			\OCP\SystemTag\ManagerEvent::EVENT_DELETE,
		] as $event) {
			$context->registerEventListener($event, \OCA\BuscadorDocumental\Listener\OutboxListener::class);
		}
		foreach ([\OCP\User\Events\BeforeUserDeletedEvent::class, \OCP\User\Events\UserDeletedEvent::class] as $event) {
			$context->registerEventListener($event, \OCA\BuscadorDocumental\Listener\UserDeletionListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
