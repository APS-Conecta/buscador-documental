<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\FileFacts;
use OCA\BuscadorDocumental\Acl\FileFactsSource;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\FileIndexer;

/** What re-enqueueing does to a file's index state: permanent verdicts stand, and an unchanged ACL is not a change. */
class FileIndexerTest extends DbTestCase {
	private string $etag = 'e1';
	/** @var list<string> */
	private array $shares = [];

	private function indexer(): FileIndexer {
		$facts = $this->createMock(FileFactsSource::class);
		$facts->method('load')->willReturnCallback(fn (int $id): FileFacts => new FileFacts($id, 'a.pdf', 'application/pdf', 1, $this->etag, 'a', $this->shares, [], [], false, 7));
		$exclusion = $this->createMock(ExclusionChecker::class);
		$exclusion->method('isExcluded')->willReturn(false);
		$db = new IndexDb($this->connection($this->app()));
		return new FileIndexer($db, $facts, new PrincipalWriter($db, $facts), $exclusion);
	}

	private function state(int $id): string {
		return (string)$this->app()->query("SELECT text_state || '/' || coalesce(skip_reason, '')  FROM aps_search.file_state WHERE file_id = $id")->fetchColumn();
	}

	private function skip(int $id, string $reason): void {
		$this->app()->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES ($id, 'e1', 'skipped', '$reason')");
	}

	/** @return list<string> */

	public function testPermanentSkipsStandWhileTheEtagIsUnchanged(): void {
		foreach (['encrypted', 'corrupt', 'unsafe', 'too_large', 'unsupported', 'internal', 'timeout', 'ocr_failed'] as $i => $reason) {
			$id = 10 + $i;
			$this->skip($id, $reason);
			$this->assertTrue($this->indexer()->enqueue($id));
			$this->assertSame("skipped/$reason", $this->state($id), $reason);
			$this->assertSame(0, $this->rowCount('job', $id), "$reason: no job for a permanent skip");
		}
	}

	public function testAChangedEtagGivesAPermanentSkipAnotherChance(): void {
		$this->skip(10, 'encrypted');
		$this->etag = 'e2';
		$this->indexer()->enqueue(10);
		$this->assertSame('pending/', $this->state(10));
		$this->assertSame(1, $this->rowCount('job', 10));
	}

	public function testSkipsThatDependOnPolicyAreReEvaluated(): void {
		foreach (['excluded', 'out_of_scope', 'trashed', 'user_deleted'] as $i => $reason) {
			$id = 20 + $i;
			$this->skip($id, $reason);
			$this->indexer()->enqueue($id);
			$this->assertSame('pending/', $this->state($id), $reason);
			$this->assertSame(1, $this->rowCount('job', $id), $reason);
		}
	}

	public function testAnUnchangedAclDoesNotBumpTheVersionAndAChangedOneDoes(): void {
		$ver = fn (): int => (int)$this->app()->query('SELECT acl_ver FROM aps_search.file_acl WHERE file_id = 30')->fetchColumn();
		$this->indexer()->enqueue(30);
		$this->assertSame(1, $ver());
		$this->indexer()->enqueue(30);
		$this->indexer()->enqueue(30);
		$this->assertSame(1, $ver(), 're-evaluation with the same principals is not a change');
		$this->shares = ['b'];
		$this->indexer()->enqueue(30);
		$this->assertSame(2, $ver());
	}
}
