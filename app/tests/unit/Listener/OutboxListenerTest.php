<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Listener;

use OCA\BuscadorDocumental\Index\Outbox;
use OCA\BuscadorDocumental\Listener\OutboxListener;
use OCP\Files\Cache\CacheEntriesRemovedEvent;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Cache\CacheEntryUpdatedEvent;
use OCP\Files\Storage\IStorage;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagNotFoundException;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OutboxListenerTest extends TestCase {
	/** @var list<array{0: int, 1: string}> */
	private array $touched = [];
	private LoggerInterface $logger;

	private function listener(?\Throwable $outboxFails = null): OutboxListener {
		$outbox = $this->createMock(Outbox::class);
		$record = function (array $ids, string $kind) use ($outboxFails): void {
			if ($outboxFails !== null) {
				throw $outboxFails;
			}
			foreach ($ids as $id) {
				$this->touched[] = [$id, $kind];
			}
		};
		$outbox->method('touch')->willReturnCallback(fn (int $id, string $kind) => $record([$id], $kind));
		$outbox->method('touchMany')->willReturnCallback(fn (array $ids, string $kind) => $record($ids, $kind));
		$this->logger = $this->createMock(LoggerInterface::class);
		// The events carry tag IDS (list<int>), not tag objects (NC 34 TagAssignedEvent::getTags): the listener resolves names.
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTagsByIds')->willReturnCallback(function (array $ids): array {
			$known = ['3' => 'rag-excluded', '4' => 'urgente', '5' => 'Rag-Excluded'];
			$out = [];
			foreach ($ids as $id) {
				if (!isset($known[(string)$id])) {
					throw new TagNotFoundException('no such tag');
				}
				$out[(string)$id] = $this->tag($known[(string)$id]);
			}
			return $out;
		});
		return new OutboxListener($outbox, $tags, $this->logger);
	}

	private function storage(): IStorage {
		return $this->createMock(IStorage::class);
	}

	private function tag(string $name): ISystemTag {
		$t = $this->createMock(ISystemTag::class);
		$t->method('getName')->willReturn($name);
		return $t;
	}

	public function testCacheInsertUpdateAndRemoveBecomeFileHints(): void {
		$l = $this->listener();
		$l->handle(new CacheEntryInsertedEvent($this->storage(), 'files/a.pdf', 10, 1));
		$l->handle(new CacheEntryUpdatedEvent($this->storage(), 'files/a.pdf', 11, 1));
		$l->handle(new CacheEntryRemovedEvent($this->storage(), 'files/a.pdf', 12, 1));
		$this->assertSame([[10, 'file'], [11, 'file'], [12, 'file']], $this->touched);
	}

	public function testTheBulkRemovalEventBecomesOneHintPerEntry(): void {
		$l = $this->listener();
		$l->handle(new CacheEntriesRemovedEvent([new CacheEntryRemovedEvent($this->storage(), 'files/x/1', 21, 1), new CacheEntryRemovedEvent($this->storage(), 'files/x/2', 22, 1)]));
		$this->assertSame([[21, 'file'], [22, 'file']], $this->touched);
	}

	public function testAppDataChurnIsIgnored(): void {
		$l = $this->listener();
		$l->handle(new CacheEntryInsertedEvent($this->storage(), 'appdata_oc1234/preview/9/9.jpg', 30, 5));
		$this->assertSame([], $this->touched);
	}

	public function testSharesBecomeAclHintsOnTheirNode(): void {
		$l = $this->listener();
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn(55);
		$l->handle(new ShareCreatedEvent($share));
		$l->handle(new ShareDeletedEvent($share));
		$this->assertSame([[55, 'acl'], [55, 'acl']], $this->touched);
	}

	public function testOnlyTheExclusionTagOnFilesTriggersAnEvaluation(): void {
		$l = $this->listener();
		$l->handle(new TagAssignedEvent('files', ['7', '8'], [3]));
		$l->handle(new TagUnassignedEvent('files', ['9'], [3]));
		$l->handle(new TagAssignedEvent('files', ['10'], [4]));
		$l->handle(new TagAssignedEvent('calendar', ['11'], [3]));
		$l->handle(new TagAssignedEvent('files', ['12'], [4, 3]));
		$this->assertSame([[7, 'subtree'], [8, 'subtree'], [9, 'subtree'], [12, 'subtree']], $this->touched);
	}

	public function testACaseVariantOfTheExclusionTagOnFilesTriggersAnEvaluation(): void {
		$l = $this->listener();
		$l->handle(new TagAssignedEvent('files', ['7'], [5]));
		$l->handle(new TagUnassignedEvent('files', ['8'], [5]));
		$this->assertSame([[7, 'subtree'], [8, 'subtree']], $this->touched);
	}

	public function testAListenerNeverThrowsIntoTheRequestAndLogsOnlyTheClassName(): void {
		$l = $this->listener(new \RuntimeException('contrato de Juan Pérez.pdf'));
		$captured = [];
		$this->logger->method('warning')->willReturnCallback(function (string $m) use (&$captured): void {
			$captured[] = $m;
		});
		$l->handle(new CacheEntryInsertedEvent($this->storage(), 'files/a.pdf', 10, 1));
		$this->assertNotEmpty($captured);
		$this->assertStringNotContainsString('Juan', implode(' ', $captured));
		$this->assertStringContainsString('RuntimeException', implode(' ', $captured));
	}

	public function testDeletingTheExclusionTagTriggersAGlobalReevaluation(): void {
		$l = $this->listener();
		$l->handle(new \OCP\SystemTag\ManagerEvent(\OCP\SystemTag\ManagerEvent::EVENT_DELETE, $this->tag('rag-excluded')));
		$l->handle(new \OCP\SystemTag\ManagerEvent(\OCP\SystemTag\ManagerEvent::EVENT_DELETE, $this->tag('urgente')));
		$l->handle(new \OCP\SystemTag\ManagerEvent(\OCP\SystemTag\ManagerEvent::EVENT_CREATE, $this->tag('rag-excluded')));
		$this->assertSame([[0, 'rule']], $this->touched);
	}

	public function testDeletingACaseVariantOfTheExclusionTagTriggersAGlobalReevaluation(): void {
		$l = $this->listener();
		$l->handle(new \OCP\SystemTag\ManagerEvent(\OCP\SystemTag\ManagerEvent::EVENT_DELETE, $this->tag('Rag-Excluded')));
		$this->assertSame([[0, 'rule']], $this->touched);
	}

	public function testATagThatNoLongerExistsIsNotAnError(): void {
		$l = $this->listener();
		$this->logger->expects($this->never())->method('warning');
		$l->handle(new TagAssignedEvent('files', ['7'], [999]));
		$this->assertSame([], $this->touched);
	}

	public function testTheLogLineNamesTheFailureLocationButNeverTheMessage(): void {
		$l = $this->listener(new \RuntimeException('contrato de Juan Pérez.pdf'));
		$captured = [];
		$this->logger->method('warning')->willReturnCallback(function (string $m) use (&$captured): void {
			$captured[] = $m;
		});
		$l->handle(new CacheEntryInsertedEvent($this->storage(), 'files/a.pdf', 10, 1));
		$this->assertMatchesRegularExpression('/RuntimeException at \w+\.php:\d+/', implode(' ', $captured));
	}
}
