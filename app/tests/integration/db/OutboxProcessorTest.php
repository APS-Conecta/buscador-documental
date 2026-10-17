<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\FileIndexer;
use OCA\BuscadorDocumental\Index\Outbox;
use OCA\BuscadorDocumental\Index\OutboxRunner;
use OCA\BuscadorDocumental\Index\Processor;
use OCA\BuscadorDocumental\Scope\FileTree;
use OCA\BuscadorDocumental\Scope\ScopePredicate;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use Psr\Log\LoggerInterface;

/** S3 phase 4: the processor re-evaluates a file from the truth (file cache, tags, rules); events are only hints. */
class OutboxProcessorTest extends DbTestCase {
	/** Fixture (see fixture()): home storage 1 files 10..17, groupfolder storage 2 files 20..23. */
	private const TREE = [10 => -1, 11 => 10, 12 => 11, 13 => 12, 14 => 10, 15 => 14, 17 => 12, 20 => -1, 21 => 20];

	/** @var list<int> */
	private array $enqueued = [];
	/** @var list<int> */
	private array $aclWritten = [];

	private function fixture(): void {
		$admin = self::connect('postgres', 'dev', self::$dbName);
		$admin->exec('DROP TABLE IF EXISTS oc_filecache, oc_storages, oc_group_folders, oc_mimetypes');
		$admin->exec('CREATE TABLE oc_mimetypes (id bigint primary key, mimetype text)');
		$admin->exec("INSERT INTO oc_mimetypes VALUES (1, 'httpd/unix-directory'), (2, 'application/pdf')");
		$admin->exec('CREATE TABLE oc_storages (numeric_id bigint primary key, id text)');
		$admin->exec('CREATE TABLE oc_group_folders (folder_id bigint primary key, storage_id bigint)');
		$admin->exec('CREATE TABLE oc_filecache (fileid bigint primary key, storage bigint, path text, parent bigint, mimetype bigint default 2, size bigint default 10)');
		$admin->exec('CREATE INDEX ON oc_filecache (parent)');
		$admin->exec("INSERT INTO oc_storages VALUES (1, 'home::alice'), (2, 'local::/var/www/html/data/__groupfolders/1/')");
		$admin->exec("INSERT INTO oc_filecache (fileid, storage, path, parent, mimetype) VALUES
			(10, 1, 'files', -1, 1), (11, 1, 'files/top', 10, 1), (12, 1, 'files/top/mid', 11, 1), (13, 1, 'files/top/mid/a.pdf', 12, 2),
			(14, 1, 'files/other', 10, 1), (15, 1, 'files/other/b.pdf', 14, 2), (16, 1, 'files_trashbin/files/old.pdf.d1', -1, 2),
			(17, 1, 'files/top/mid/c.pdf', 12, 2), (20, 2, 'files', -1, 1), (21, 2, 'files/team.pdf', 20, 2)");
		$admin->exec('GRANT SELECT ON oc_filecache, oc_storages, oc_group_folders, oc_mimetypes TO nextcloud');
	}

	private function build(array $tagged = [], ?\Throwable $exclusionFails = null, array $tree = self::TREE): array {
		$this->fixture();
		$conn = $this->connection($this->app());
		$db = new IndexDb($conn);
		$audit = new AuditLog($db, new Pseudonyms($db));
		$rules = new ScopeRules($db, $audit);
		$settings = new Settings($db);
		$this->enqueued = [];
		$this->aclWritten = [];
		$indexer = $this->createMock(FileIndexer::class);
		$indexer->method('enqueue')->willReturnCallback(function (int $id): bool {
			$this->enqueued[] = $id;
			return true;
		});
		$acl = $this->createMock(PrincipalWriter::class);
		$acl->method('write')->willReturnCallback(function (int $id): bool {
			$this->aclWritten[] = $id;
			return true;
		});
		$exclusion = TreeChecker::make($tree, [[3, ExclusionChecker::TAG_NAME]], $tagged, $exclusionFails);
		$tree = new FileTree($conn);
		$processor = new Processor($db, $tree, $rules, new ScopePredicate($tree), $exclusion, $indexer, $acl, $settings, $this->createMock(LoggerInterface::class));
		return [$db, $rules, $settings, $processor, new Outbox($db), $audit];
	}

	private function indexedRow(int $id): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, 'e', 'indexed')");
		$a->exec("INSERT INTO aps_search.page (file_id, page_no, text) VALUES ($id, 1, 'x')");
		$a->exec("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES ($id, 1, 0, 'x')");
		$a->exec("INSERT INTO aps_search.file_acl (file_id, principals) VALUES ($id, ARRAY['u:alice'])");
		$a->exec("INSERT INTO aps_search.file_meta (file_id, name) VALUES ($id, 'x')");
	}

	public function testAFileGoneFromNextcloudIsPurgedAndLeavesATombstone(): void {
		[$db, , , $p] = $this->build();
		$this->indexedRow(99);
		$this->assertSame('purged', $p->process(99, 'file'));
		foreach (['file_state', 'chunk', 'page', 'file_acl', 'file_meta'] as $t) {
			$this->assertSame(0, $this->rowCount($t, 99), $t);
		}
		$this->assertSame(1, (int)$db->rows("SELECT count(*) AS n FROM aps_search.tombstone WHERE file_id = 99 AND reason = 'deleted'")[0]['n']);
	}

	public function testAFileInTheTrashIsErasedAtOnceWithAThirtyDayLedger(): void {
		[$db, , , $p] = $this->build();
		$this->indexedRow(16);
		$this->assertSame('erased_trashed', $p->process(16, 'file'));
		$this->assertSame(0, $this->rowCount('chunk', 16));
		$this->assertSame('skipped:trashed', $db->rows("SELECT text_state || ':' || skip_reason AS s FROM aps_search.file_state WHERE file_id = 16")[0]['s']);
		$days = (float)$db->rows("SELECT extract(epoch FROM purge_after - created_at) / 86400 AS d FROM aps_search.tombstone WHERE file_id = 16")[0]['d'];
		$this->assertEqualsWithDelta(30, $days, 0.01);
		$this->assertSame([], $this->enqueued);
	}

	public function testAnExcludedFileIsErasedAndNeverEnqueued(): void {
		[$db, $rules, , $p] = $this->build(['11' => ['3']]);            // the tag sits on folder 11, an ancestor of 13
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$this->assertSame('erased_excluded', $p->process(13, 'file'));
		$this->assertSame(0, $this->rowCount('chunk', 13));
		$this->assertSame('skipped:excluded', $db->rows("SELECT text_state || ':' || skip_reason AS s FROM aps_search.file_state WHERE file_id = 13")[0]['s']);
		$this->assertSame([], $this->enqueued);
	}

	public function testAnIndexedFileThatLeavesTheScopeIsErasedButAnUnknownOneIsIgnored(): void {
		[, $rules, , $p] = $this->build();
		$id = $rules->addFolder(11, 'occ');                              // covers 13 and 17, not 15
		$this->indexedRow(15);
		$this->assertSame('erased_out_of_scope', $p->process(15, 'file'));
		$this->assertSame(0, $this->rowCount('chunk', 15));
		$this->assertSame('ignored', $p->process(21, 'file'), 'a file never indexed and out of scope is left alone');
		$this->assertSame(0, $this->rowCount('file_state', 21));
		$this->assertSame([], $this->enqueued);
		$rules->remove($id, 'occ');
	}

	public function testAnInScopeFileIsEnqueuedOnceAndTheEvaluationIsIdempotent(): void {
		[, $rules, , $p] = $this->build();
		$rules->addFolder(11, 'occ');
		$this->assertSame('enqueued', $p->process(13, 'file'));
		$this->assertSame('enqueued', $p->process(13, 'file'));
		$this->assertSame([13, 13], $this->enqueued, 'the indexer itself is idempotent on (file, etag); the processor just asks');
	}

	public function testTheTeamFolderRuleCoversTeamFolderFiles(): void {
		[, $rules, , $p] = $this->build();
		$rules->addGroupfolders('occ');
		$this->assertSame('enqueued', $p->process(21, 'file'));
		$this->assertSame('ignored', $p->process(13, 'file'));
	}

	public function testPausedHoldsAdditionsButNotRemovals(): void {
		[, $rules, $settings, $p] = $this->build(['11' => ['3']]);
		$rules->addFolder(10, 'occ');
		$settings->setPaused(true);
		$this->assertSame('held', $p->process(15, 'file'), 'an addition waits while paused');
		$this->indexedRow(13);
		$this->assertSame('erased_excluded', $p->process(13, 'file'), 'exclusion is never held back');
		$this->assertSame([], $this->enqueued);
	}

	public function testAnUnavailableExclusionStateIndexesNothing(): void {
		[, $rules, , $p] = $this->build([], new \RuntimeException('tag service down'));
		$rules->addFolder(10, 'occ');
		$this->assertSame('retry', $p->process(13, 'file'));
		$this->assertSame([], $this->enqueued);
	}

	public function testAFolderEventExpandsToItsFilesOnly(): void {
		[$db, , , $p] = $this->build();
		$this->assertSame('expanded', $p->process(11, 'file'));                 // a folder arriving as a plain file event
		$ids = array_map('intval', array_column($db->rows("SELECT file_id FROM aps_search.outbox WHERE kind = 'file' ORDER BY file_id"), 'file_id'));
		$this->assertSame([13, 17], $ids, 'files under 11, never the folders themselves');
	}

	public function testAShareOnAFolderMarksIndexedDescendantsAndTheRefreshClearsThem(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$this->indexedRow(15);
		$this->assertSame('acl_marked', $p->process(11, 'acl'));
		$dirty = array_map('intval', array_column($db->rows('SELECT file_id FROM aps_search.file_state WHERE acl_dirty ORDER BY 1'), 'file_id'));
		$this->assertSame([13], $dirty, 'only indexed files below the shared folder');
		$this->assertSame(1, $p->refreshDirty(100));
		$this->assertSame([13], $this->aclWritten);
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n']);
	}

	public function testTagDeletionRequeuesPreviouslyExcludedFiles(): void {
		[$db, , , $p] = $this->build();
		$db->execute("INSERT INTO aps_search.file_state (file_id, text_state, skip_reason) VALUES (13, 'skipped', 'excluded'), (15, 'skipped', 'encrypted')");
		$this->assertSame('rule', $p->process(0, 'rule'));
		$ids = array_map('intval', array_column($db->rows("SELECT file_id FROM aps_search.outbox ORDER BY 1"), 'file_id'));
		$this->assertSame([13], $ids);
	}

	// ---- the runner ----
	public function testTheRunnerProcessesABatchMarksRowsDoneAndRetriesFailuresUpToFiveTimes(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$outbox->touch(13, 'file');
		$outbox->touch(17, 'file');
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$this->assertSame(['enqueued' => 2], $runner->runBatch(50));
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.outbox WHERE processed_at IS NULL')[0]['n']);

		[$db2, , , $p2, $outbox2] = $this->build([], new \RuntimeException('down'));
		$db2->execute('DELETE FROM aps_search.outbox');
		(new ScopeRules($db2, new AuditLog($db2, new Pseudonyms($db2))))->addFolder(11, 'occ');
		$outbox2->touch(13, 'file');
		$runner2 = new OutboxRunner($db2, $outbox2, $p2, $this->createMock(LoggerInterface::class));
		for ($i = 1; $i <= 5; $i++) {
			$this->assertSame(['retry' => 1], $runner2->runBatch(50), "attempt $i");
		}
		$this->assertSame([], $runner2->runBatch(50), 'parked after five attempts: not picked again');
		$this->assertSame(1, (int)$db2->rows('SELECT count(*) AS n FROM aps_search.outbox WHERE processed_at IS NULL AND attempts >= 5')[0]['n']);
	}

	public function testPausedRowsAreLeftWaitingWithoutBurningAttempts(): void {
		[$db, $rules, $settings, $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$settings->setPaused(true);
		$outbox->touch(13, 'file');
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		for ($i = 0; $i < 8; $i++) {
			$this->assertSame(['held' => 1], $runner->runBatch(50));
		}
		$this->assertSame(0, (int)$db->rows('SELECT attempts FROM aps_search.outbox')[0]['attempts']);
		$settings->setPaused(false);
		$this->assertSame(['enqueued' => 1], $runner->runBatch(50));
	}

	public function testATenThousandEventStormIsOneRow(): void {
		[$db, , , , $outbox] = $this->build();
		for ($i = 0; $i < 10000; $i++) {
			$outbox->touch(13, 'file');
		}
		$this->assertSame(1, (int)$db->rows('SELECT count(*) AS n FROM aps_search.outbox')[0]['n']);
	}

	public function testTouchManyIsDeduplicatedToo(): void {
		[$db, , , , $outbox] = $this->build();
		$outbox->touchMany([1, 2, 2, 3, 1], 'file');
		$this->assertSame(3, (int)$db->rows('SELECT count(*) AS n FROM aps_search.outbox')[0]['n']);
	}

	public function testARowClaimedByACrashedRunIsTakenAgain(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$outbox->touch(13, 'file');
		$claimed = $outbox->claim(10);                                    // claims, then the run "crashes" before marking
		$this->assertCount(1, $claimed);
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$this->assertSame(['enqueued' => 1], $runner->runBatch(50), 'the next run picks the unfinished row again');
		$this->assertSame(1, $this->rowCount('outbox') , 'one row, now processed');
	}

	public function testOneRunKeepsTakingBatchesUntilTheQueueIsEmptyOrTheBudgetEnds(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT g, 'file' FROM generate_series(1000, 1449) g");      // 450 rows
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$counts = $runner->runFor(30, 200);
		$this->assertSame(450, array_sum($counts), 'three batches in one run');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.outbox WHERE processed_at IS NULL')[0]['n']);
	}

	public function testAZeroBudgetStillTakesOneBatchSoTheQueueAlwaysMoves(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT g, 'file' FROM generate_series(1000, 1449) g");
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$this->assertSame(200, array_sum($runner->runFor(0, 200)));
		$this->assertSame(250, (int)$db->rows('SELECT count(*) AS n FROM aps_search.outbox WHERE processed_at IS NULL')[0]['n']);
	}

	public function testHeldRowsDoNotMakeTheRunSpinForeverWhilePaused(): void {
		[$db, $rules, $settings, $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$settings->setPaused(true);
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT g, 'file' FROM generate_series(13, 17) g WHERE g IN (13, 17)");
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$start = microtime(true);
		$runner->runFor(30, 200);
		$this->assertLessThan(5, microtime(true) - $start, 'a run that can make no progress ends at once');
	}
}
