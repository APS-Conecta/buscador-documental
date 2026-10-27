<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Migration;

use OCA\BuscadorDocumental\Migration\EnsureExclusionTag;
use OCP\Migration\IOutput;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;
use PHPUnit\Framework\TestCase;

/** Without the tag the exclusion check fails closed and nothing is ever indexed: the app creates it when it is enabled. */
class EnsureExclusionTagTest extends TestCase {
	public function testItCreatesAPublicAssignableTagWhenNoneExists(): void {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([]);
		$m->expects($this->once())->method('createTag')->with('rag-excluded', true, true);
		(new EnsureExclusionTag($m))->run($this->createMock(IOutput::class));
	}

	public function testItLeavesAnExistingTagAlone(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('rag-excluded');
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$tag]);
		$m->expects($this->never())->method('createTag');
		(new EnsureExclusionTag($m))->run($this->createMock(IOutput::class));
	}

	public function testItLeavesACaseVariantAlone(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('RAG-excluded');
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$tag]);
		$m->expects($this->never())->method('createTag');
		(new EnsureExclusionTag($m))->run($this->createMock(IOutput::class));
	}

	public function testANameThatOnlyContainsTheTagNameIsNotTheTag(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('rag-excluded-old');
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([$tag]);
		$m->expects($this->once())->method('createTag')->with('rag-excluded', true, true);
		(new EnsureExclusionTag($m))->run($this->createMock(IOutput::class));
	}

	public function testARaceWithAnotherCreatorIsHarmless(): void {
		$m = $this->createMock(ISystemTagManager::class);
		$m->method('getAllTags')->willReturn([]);
		$m->method('createTag')->willThrowException(new TagAlreadyExistsException('exists'));
		(new EnsureExclusionTag($m))->run($this->createMock(IOutput::class));
		$this->addToAssertionCount(1);
	}
}
