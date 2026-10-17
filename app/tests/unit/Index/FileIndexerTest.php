<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Index;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\FileFacts;
use OCA\BuscadorDocumental\Acl\FileFactsSource;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\FileIndexer;
use PHPUnit\Framework\TestCase;

class FileIndexerTest extends TestCase {
	private function indexer(?FileFacts $facts, ExclusionChecker $ex, IndexDb $db): FileIndexer {
		$src = $this->createMock(FileFactsSource::class);
		$src->method('load')->willReturn($facts);
		return new FileIndexer($db, $src, $this->createMock(PrincipalWriter::class), $ex);
	}

	private function facts(): FileFacts {
		return new FileFacts(7, 'a.pdf', 'application/pdf', 1, 'e1', 'alice');
	}

	public function testExcludedFileIsRefusedAndNothingIsWritten(): void {
		$ex = $this->createMock(ExclusionChecker::class);
		$ex->method('isExcluded')->willReturn(true);
		$db = $this->createMock(IndexDb::class);
		$db->expects($this->never())->method('execute');
		$this->assertFalse($this->indexer($this->facts(), $ex, $db)->enqueue(7));
	}

	public function testMissingFileIsRefused(): void {
		$db = $this->createMock(IndexDb::class);
		$db->expects($this->never())->method('execute');
		$this->assertFalse($this->indexer(null, $this->createMock(ExclusionChecker::class), $db)->enqueue(7));
	}

	public function testExclusionUnavailableFailsClosedWithoutWrites(): void {
		$ex = $this->createMock(ExclusionChecker::class);
		$ex->method('isExcluded')->willThrowException(new ExclusionUnavailable('tag service down'));
		$db = $this->createMock(IndexDb::class);
		$db->expects($this->never())->method('execute');
		$this->expectException(ExclusionUnavailable::class);
		$this->indexer($this->facts(), $ex, $db)->enqueue(7);
	}

	public function testAllowedFileWritesStateMetaAclAndJob(): void {
		$ex = $this->createMock(ExclusionChecker::class);
		$ex->method('isExcluded')->willReturn(false);
		$db = $this->createMock(IndexDb::class);
		$db->expects($this->exactly(4))->method('execute');
		$this->assertTrue($this->indexer($this->facts(), $ex, $db)->enqueue(7));
	}
}
