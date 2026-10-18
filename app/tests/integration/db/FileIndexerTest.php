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
	/** @var list<int> */
	private array $ancestors = [3, 1];
	private string $mime = 'application/pdf';
	private ?string $owner = 'a';

	private function indexer(): FileIndexer {
		$facts = $this->createMock(FileFactsSource::class);
		$facts->method('load')->willReturnCallback(fn (int $id): FileFacts => new FileFacts($id, 'a.pdf', $this->mime, 1, $this->etag, $this->owner, $this->shares, [], [], false, 7, $this->ancestors));
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

	public function testTheFolderChainIsStoredAndFollowsAMove(): void {
		$read = fn (): string => (string)$this->app()->query('SELECT ancestors FROM aps_search.file_meta WHERE file_id = 40')->fetchColumn();
		$this->indexer()->enqueue(40);
		$this->assertSame('{3,1}', $read());
		$this->ancestors = [9, 3, 1];                                        // moved into a new subfolder: same etag, new chain
		$this->indexer()->enqueue(40);
		$this->assertSame('{9,3,1}', $read());
	}

	public function testTheFolderFilterFindsDescendantsThroughTheIndex(): void {
		$this->indexer()->enqueue(41);
		$this->ancestors = [8, 1];
		$this->indexer()->enqueue(42);
		$ids = $this->app()->query('SELECT file_id FROM aps_search.file_meta WHERE ancestors @> ARRAY[3]::bigint[] ORDER BY 1')->fetchAll(\PDO::FETCH_COLUMN);
		$this->assertSame([41], array_map('intval', $ids));
	}

	public function testEnqueueNeverDropsTheVectorLanesJobs(): void {
		$this->indexer()->enqueue(50);
		$this->app()->exec("INSERT INTO aps_search.job (lane, file_id, idem_key, gen, attempts, not_before) VALUES ('vec', 50, '50:e1:1', 1, 3, now() + interval '1 hour')");
		$this->indexer()->enqueue(50);                                       // same etag: nothing to replace
		$this->etag = 'e2';
		$this->indexer()->enqueue(50);                                       // new etag: the idle TEXT job of the old etag goes, the vector job is not this method's to touch
		$row = $this->app()->query("SELECT attempts FROM aps_search.job WHERE lane = 'vec' AND file_id = 50")->fetchColumn();
		$this->assertSame(3, (int)$row, 'a backing-off vector job keeps its attempts');
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM aps_search.job WHERE lane = 'text' AND file_id = 50")->fetchColumn());
	}

	public function testTheOwnerIsStoredForThePropiedadFilterAndTeamFoldersHaveNone(): void {
		$read = fn (int $id): ?string => $this->app()->query("SELECT owner FROM aps_search.file_meta WHERE file_id = $id")->fetchColumn() ?: null;
		$this->indexer()->enqueue(60);
		$this->assertSame('a', $read(60));
		$this->owner = null;
		$this->indexer()->enqueue(61);
		$this->assertNull($read(61));
		$this->owner = 'b';                                                  // the file changed hands
		$this->indexer()->enqueue(60);
		$this->assertSame('b', $read(60));
	}
}
