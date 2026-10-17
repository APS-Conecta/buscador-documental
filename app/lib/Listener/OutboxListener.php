<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Listener;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Index\Outbox;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\AbstractCacheEvent;
use OCP\Files\Cache\CacheEntriesRemovedEvent;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ManagerEvent;
use OCP\SystemTag\TagNotFoundException;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Events are hints (ADR-0013): this stores a file id and a kind, nothing else, and never lets a failure reach the
 * request that caused the event. What the hint means is decided later by the processor from the truth.
 *
 * @template-implements IEventListener<Event>
 */
class OutboxListener implements IEventListener {
	public function __construct(private Outbox $outbox, private ISystemTagManager $tags, private LoggerInterface $logger) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof CacheEntriesRemovedEvent) {
				$ids = [];
				foreach ($event->getCacheEntryRemovedEvents() as $e) {
					if (!$this->ignored($e->getPath())) {
						$ids[] = $e->getFileId();
					}
				}
				$this->outbox->touchMany($ids, 'file');
			} elseif ($event instanceof AbstractCacheEvent) {
				if (!$this->ignored($event->getPath())) {
					$this->outbox->touch($event->getFileId(), 'file');
				}
			} elseif ($event instanceof ShareCreatedEvent || $event instanceof ShareDeletedEvent) {
				$nodeId = $event->getShare()->getNodeId();
				if ($nodeId > 0) {
					$this->outbox->touch($nodeId, 'acl');
				}
			} elseif ($event instanceof TagAssignedEvent || $event instanceof TagUnassignedEvent) {
				if ($event->getObjectType() === 'files' && $this->hasExclusionTag($event->getTags())) {
					$this->outbox->touchMany(array_map('intval', $event->getObjectIds()), 'file');
				}
			} elseif ($event instanceof ManagerEvent) {
				if ($event->getEvent() === ManagerEvent::EVENT_DELETE && $event->getTag()->getName() === ExclusionChecker::TAG_NAME) {
					$this->outbox->touch(0, 'rule');
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('buscador_documental: could not record an event (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	}

	/** Thumbnail and app-data churn is by far the noisiest source and never concerns a user file. */
	private function ignored(string $path): bool {
		return str_starts_with($path, 'appdata_');
	}

	/** The tag events carry tag ids (list<int>), not tag objects: resolve the names. @param list<int|string> $tagIds */
	private function hasExclusionTag(array $tagIds): bool {
		try {
			foreach ($this->tags->getTagsByIds(array_map('strval', $tagIds)) as $tag) {
				if ($tag->getName() === ExclusionChecker::TAG_NAME) {
					return true;
				}
			}
		} catch (TagNotFoundException) {
			return false;                               // the tag is already gone: nothing to evaluate by it
		}
		return false;
	}
}
