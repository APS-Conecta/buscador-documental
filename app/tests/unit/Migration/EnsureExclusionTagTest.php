<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Migration;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Migration\EnsureExclusionTag;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use PHPUnit\Framework\TestCase;

/** Folder owners can opt a folder out only while the tag exists: the app creates it when it is enabled. */
class EnsureExclusionTagTest extends TestCase {
	private function step(ISystemTagManager $m): EnsureExclusionTag {
		return new EnsureExclusionTag(new ExclusionChecker($this->createMock(IDBConnection::class), $m, $this->createMock(ISystemTagObjectMapper::class)));
	}

	public function testItCreatesAPublicAssignableTagWhenNoneExists(): void {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([]);
		$m->expects($this->once())->method('createTag')->with('rag-excluded', true, true);
		$out = $this->createMock(IOutput::class);
		$out->expects($this->once())->method('info')->with('rag-excluded tag created');
		$this->step($m)->run($out);
	}

	public function testItLeavesAnExistingTagAlone(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('rag-excluded');
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$tag]);
		$m->expects($this->never())->method('createTag');
		$this->step($m)->run($this->createMock(IOutput::class));
	}

	public function testItLeavesACaseVariantAlone(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('RAG-excluded');
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$tag]);
		$m->expects($this->never())->method('createTag');
		$this->step($m)->run($this->createMock(IOutput::class));
	}

	public function testANameThatOnlyContainsTheTagNameIsNotTheTag(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('rag-excluded-old');
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$tag]);
		$m->expects($this->once())->method('createTag')->with('rag-excluded', true, true);
		$this->step($m)->run($this->createMock(IOutput::class));
	}

	public function testARaceWithAnotherCreatorIsHarmless(): void {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([]);
		$m->method('createTag')->willThrowException(new TagAlreadyExistsException('exists'));
		$out = $this->createMock(IOutput::class);
		$out->expects($this->never())->method('info');
		$this->step($m)->run($out);
	}
}
