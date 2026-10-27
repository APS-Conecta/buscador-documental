<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Acl;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/** Fake filecache: id => parent (<= 0 is a storage root). */
class FakeTreeChecker extends ExclusionChecker {
	public function __construct(private array $tree, IDBConnection $db, ISystemTagManager $m, ISystemTagObjectMapper $o) {
		parent::__construct($db, $m, $o);
	}

	protected function parentOf(int $fileId): ?int {
		return $this->tree[$fileId] ?? null;
	}
}

class ExclusionCheckerTest extends TestCase {
	private function tag(int $id, string $name): ISystemTag {
		$t = $this->createMock(ISystemTag::class);
		$t->method('getId')->willReturn((string)$id);
		$t->method('getName')->willReturn($name);
		return $t;
	}

	private function checker(array $tree, array $tags, array $tagged, ?\Throwable $mapperFails = null): ExclusionChecker {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn($tags);
		$o = $this->createMock(ISystemTagObjectMapper::class);
		if ($mapperFails !== null) {
			$o->method('getTagIdsForObjects')->willThrowException($mapperFails);
		} else {
			$o->method('getTagIdsForObjects')->willReturn($tagged);
		}
		return new FakeTreeChecker($tree, $this->createMock(IDBConnection::class), $m, $o);
	}

	public function testTagOnFileExcludes(): void {
		$c = $this->checker([10 => 5, 5 => 1, 1 => -1], [$this->tag(3, 'rag-excluded')], ['10' => ['3']]);
		$this->assertTrue($c->isExcluded(10));
	}

	public function testTagOnAncestorAboveViewerMountExcludes(): void {
		$c = $this->checker([10 => 5, 5 => 1, 1 => -1], [$this->tag(3, 'rag-excluded')], ['1' => ['3']]);
		$this->assertTrue($c->isExcluded(10));
	}

	public function testUntaggedFileIsNotExcluded(): void {
		$c = $this->checker([10 => 5, 5 => 1, 1 => -1], [$this->tag(3, 'rag-excluded')], ['10' => ['9']]);
		$this->assertFalse($c->isExcluded(10));
	}

	public function testDuplicateTagNamesAllCount(): void {
		$c = $this->checker([10 => 1, 1 => -1], [$this->tag(3, 'rag-excluded'), $this->tag(4, 'rag-excluded')], ['10' => ['4']]);
		$this->assertTrue($c->isExcluded(10));
	}

	public function testNoSuchTagExcludesNothingAndCreatesNothing(): void {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([]);
		$m->expects($this->never())->method('createTag');
		$o = $this->createMock(ISystemTagObjectMapper::class);
		$o->expects($this->never())->method('getTagIdsForObjects');
		$c = new FakeTreeChecker([10 => 5, 5 => 1, 1 => -1], $this->createMock(IDBConnection::class), $m, $o);
		$this->assertSame([10 => false], $c->excludedAmong([10]));

		$this->expectException(\OCA\BuscadorDocumental\Acl\FileGone::class);
		$this->checker([], [], [])->isExcluded(10);
	}

	public function testACaseVariantTagStillExcludes(): void {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$this->tag(3, 'RAG-excluded')]);
		$m->expects($this->never())->method('createTag');
		$o = $this->createMock(ISystemTagObjectMapper::class);
		$o->method('getTagIdsForObjects')->willReturn(['5' => ['3']]);
		$c = new FakeTreeChecker([10 => 5, 5 => 1, 1 => -1], $this->createMock(IDBConnection::class), $m, $o);
		$this->assertTrue($c->isExcluded(10), 'Nextcloud compares tag names case-insensitively: this tag is the control');
		$this->assertFalse($c->ensureTag(), 'a case variant is the tag: nothing is created beside it');
	}

	public function testTagServiceFailureFailsClosed(): void {
		$this->expectException(ExclusionUnavailable::class);
		$this->checker([10 => -1], [$this->tag(3, 'rag-excluded')], [], new \RuntimeException('down'))->isExcluded(10);
	}

	public function testBrokenWalkFailsClosed(): void {
		$this->expectException(ExclusionUnavailable::class);
		$this->checker([10 => 5], [$this->tag(3, 'rag-excluded')], [])->isExcluded(10); // 5 has no row
	}

	public function testMissingFileIsFileGoneButMissingAncestorIsNot(): void {
		try {
			$this->checker([], [$this->tag(3, 'rag-excluded')], [])->isExcluded(10);
			$this->fail('expected FileGone');
		} catch (\OCA\BuscadorDocumental\Acl\FileGone) {
			$this->addToAssertionCount(1);
		}
		try {
			$this->checker([10 => 5], [$this->tag(3, 'rag-excluded')], [])->isExcluded(10);
			$this->fail('expected ExclusionUnavailable');
		} catch (\OCA\BuscadorDocumental\Acl\FileGone) {
			$this->fail('an ancestor with no row is not FileGone');
		} catch (ExclusionUnavailable) {
			$this->addToAssertionCount(1);
		}
	}

	public function testTooDeepWalkFailsClosed(): void {
		$tree = [];
		for ($i = 1; $i <= ExclusionChecker::MAX_DEPTH + 5; $i++) {
			$tree[$i] = $i + 1;
		}
		$this->expectException(ExclusionUnavailable::class);
		$this->checker($tree, [$this->tag(3, 'rag-excluded')], [])->isExcluded(1);
	}
}
