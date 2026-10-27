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

	private function build(array $tagged = [], ?\Throwable $exclusionFails = null, array $tree = self::TREE, ?callable $aclWrite = null, ?LoggerInterface $logger = null): array {
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
		$acl->method('write')->willReturnCallback(function (int $id) use ($aclWrite): bool {
			if ($aclWrite !== null) {
				$aclWrite($id);
			}
			$this->aclWritten[] = $id;
			return true;
		});
		$exclusion = TreeChecker::make($tree, [[3, ExclusionChecker::TAG_NAME]], $tagged, $exclusionFails);
		$tree = new FileTree($conn);
		$processor = new Processor($db, $tree, $rules, new ScopePredicate($tree), $exclusion, $indexer, $acl, $settings, $logger ?? $this->createMock(LoggerInterface::class));
		return [$db, $rules, $settings, $processor, new Outbox($db), $audit];
	}

	/** @param list<int> $ancestors the parent chain as of the file's last evaluation, closest first (FileIndexer) */
	private function indexedRow(int $id, array $ancestors = []): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, 'e', 'indexed')");
		$a->exec("INSERT INTO aps_search.page (file_id, page_no, text) VALUES ($id, 1, 'x')");
		$a->exec("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES ($id, 1, 0, 'x')");
		$a->exec("INSERT INTO aps_search.file_acl (file_id, principals) VALUES ($id, ARRAY['u:alice'])");
		$a->exec("INSERT INTO aps_search.file_meta (file_id, name, ancestors) VALUES ($id, 'x', '{" . implode(',', $ancestors) . "}')");
	}

	/** Changes the fixture's file cache the way Nextcloud does on a move (the app's DB user may only read it). */
	private function alterFileCache(string $sql): void {
		self::connect('postgres', 'dev', self::$dbName)->exec($sql);
	}

	/** @return list<int> the files waiting in the outbox */
	private function queued(IndexDb $db): array {
		return array_map('intval', array_column($db->rows("SELECT file_id FROM aps_search.outbox WHERE kind = 'file' ORDER BY file_id"), 'file_id'));
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
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');                                    // in scope, nothing below it indexed yet
		$this->assertSame('expanded', $p->process(11, 'file'));                 // a folder arriving as a plain file event
		$this->assertSame([13, 17], $this->queued($db), 'files under 11, never the folders themselves');
	}

	public function testAnUnchangedInScopeFolderIsNotExpanded(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13, [12, 11, 10]);
		$this->indexedRow(17, [12, 11, 10]);
		$this->assertSame('folder_unchanged', $p->process(11, 'file'), 'a write below 11 only corrected its mtime');
		$this->assertSame([], $this->queued($db), 'no descendant is evaluated again');
	}

	public function testAFolderOutsideTheScopeWithNothingIndexedAddsNoRows(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(20, 'occ');                                    // the team folder only, not alice's home
		$this->assertSame('folder_unchanged', $p->process(10, 'file'), 'an upload to a home root that is not in scope');
		$this->assertSame('folder_unchanged', $p->process(11, 'file'));
		$this->assertSame([], $this->queued($db));
	}

	public function testATagChangeOnAFolderAlwaysExpands(): void {
		[$db, $rules, , $p] = $this->build(['11' => ['3']]);            // rag-excluded assigned to 11
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13, [12, 11, 10]);
		$this->indexedRow(17, [12, 11, 10]);
		$this->assertSame('expanded', $p->process(11, 'subtree'), 'parent and index unchanged, yet every verdict below changed');
		$this->assertSame([13, 17], $this->queued($db));
		$this->assertSame('erased_excluded', $p->process(13, 'file'));

		[, , , $p] = $this->build();                                    // and removed again
		$this->assertSame('expanded', $p->process(11, 'subtree'));
		$this->assertSame('enqueued', $p->process(13, 'file'));
		$this->assertSame('enqueued', $p->process(13, 'subtree'), 'a file tagged directly is evaluated like any file row');
	}

	public function testAnExcludedInScopeFolderWithNothingIndexedIsNotExpanded(): void {
		[$db, $rules, , $p] = $this->build(['11' => ['3']]);
		$rules->addFolder(10, 'occ');                                    // nothing below 11 is indexed: erasure removed it
		$this->assertSame('folder_unchanged', $p->process(11, 'file'), 'a write below an excluded folder erases nothing again');
		$this->assertSame([], $this->queued($db));
	}

	public function testAFolderWhoseExclusionStateIsUnavailableIsRetriedAndAddsNoRows(): void {
		[$db, $rules, , $p] = $this->build(['11' => ['3']], new \RuntimeException('tag service down'));
		$rules->addFolder(10, 'occ');
		$this->assertSame('retry', $p->process(11, 'file'));
		$this->assertSame([], $this->queued($db));
	}

	public function testAStorageRootIsNotExpandedOnEveryWrite(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13, [12, 11, 10]);                            // the chain stops at the root: its parent -1 is never stored
		$this->assertSame('folder_unchanged', $p->process(10, 'file'));
		$this->assertSame([], $this->queued($db));
	}

	public function testAFolderMovedToAnotherParentIsExpandedAndItsFilesFollow(): void {
		[$db, $rules, , $p] = $this->build(tree: [12 => 14] + self::TREE);
		$rules->addFolder(11, 'occ');
		$this->indexedRow(13, [12, 11, 10]);
		$this->indexedRow(17, [12, 11, 10]);
		$this->alterFileCache("UPDATE oc_filecache SET path = replace(path, 'files/top/mid', 'files/other/mid') WHERE fileid IN (12, 13, 17)");
		$this->alterFileCache('UPDATE oc_filecache SET parent = 14 WHERE fileid = 12');
		$this->assertSame('expanded', $p->process(12, 'file'), 'mid moved from top (in scope) to other');
		$this->assertSame([13, 17], $this->queued($db));
		$this->assertSame('erased_out_of_scope', $p->process(13, 'file'));
	}

	public function testAFolderMovedIntoTheTrashIsExpandedAndItsFilesAreErased(): void {
		[$db, $rules, , $p] = $this->build(tree: [11 => 30, 30 => -1] + self::TREE);
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13, [12, 11, 10]);
		$this->indexedRow(17, [12, 11, 10]);
		$this->alterFileCache("INSERT INTO oc_filecache (fileid, storage, path, parent, mimetype) VALUES (30, 1, 'files_trashbin/files', -1, 1)");
		$this->alterFileCache("UPDATE oc_filecache SET path = replace(path, 'files/top', 'files_trashbin/files/top.d1') WHERE fileid IN (11, 12, 13, 17)");
		$this->alterFileCache('UPDATE oc_filecache SET parent = 30 WHERE fileid = 11');
		$this->assertSame('expanded', $p->process(11, 'file'));
		$this->assertSame([13, 17], $this->queued($db));
		$this->assertSame('erased_trashed', $p->process(13, 'file'));
	}

	public function testAMoveIsSeenWhenOnlySomeIndexedDescendantsCarryTheOldParent(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13, [12, 11, 99]);                            // evaluated while 11 still sat in folder 99
		$this->indexedRow(17, [12, 11, 10]);                            // evaluated after 11 came back to 10
		$this->assertSame('expanded', $p->process(11, 'file'), 'every indexed descendant is compared, not a sample');
		$this->assertSame([13, 17], $this->queued($db));
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
			$this->assertSame([], $runner2->runBatch(50), "attempt $i: a failed row backs off, it is not retried straight away");
			$db2->execute('UPDATE aps_search.outbox SET not_before = now() WHERE processed_at IS NULL');          // time passes
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
		$this->assertSame([], $runner->runBatch(50), 'a fresh claim belongs to a run that may still be working');
		$db->execute("UPDATE aps_search.outbox SET claimed_at = now() - interval '11 minutes'");
		$this->assertSame(['enqueued' => 1], $runner->runBatch(50), 'once the claim is stale the unfinished row is picked again');
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

	public function testOneCycleSettlesAPermissionChangeItJustLearnedAbout(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$outbox->touch(11, 'acl');                                     // a share on folder 11 (its event arrived since the last cycle)
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$runner->cycle(30, 200, 500);
		$this->assertSame([13], $this->aclWritten, 'marked and refreshed in the same cycle, not the next one');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n']);
	}

	public function testACycleRefreshesAlreadyDirtyFilesBeforeTheBatchToo(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$db->execute("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() WHERE file_id = 13");
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$runner->cycle(0, 200, 500);
		$this->assertSame([13], $this->aclWritten);
	}

	public function testAnEventThatArrivesWhileARowIsBeingProcessedIsNotLost(): void {
		[$db, , , , $outbox] = $this->build();
		$outbox->touch(13, 'file');
		$claimed = $outbox->claim(10);                                    // the row is in flight
		$outbox->touch(13, 'file');                                       // the file changes again meanwhile (tag, edit, share)
		$outbox->done(array_column($claimed, 'id'));                      // the first evaluation finishes
		$pending = $db->rows('SELECT id FROM aps_search.outbox WHERE processed_at IS NULL');
		$this->assertCount(1, $pending, 'the later event survived as its own row');
		$this->assertNotSame($claimed[0]['id'], (int)$pending[0]['id']);
	}

	public function testAParkedRowNeverBlocksALaterEvent(): void {
		[$db, $rules, , $p, $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind, attempts) VALUES (13, 'file', 5)");       // parked long ago
		$outbox->touch(13, 'file');
		$this->assertSame(2, $this->rowCount('outbox', 13), 'a fresh row next to the parked one');
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$this->assertSame(['enqueued' => 1], $runner->runBatch(50), 'and it is processed');
	}

	public function testATransientFailureCannotBurnAllAttemptsInOneRun(): void {
		[$db, $rules, , $p, $outbox] = $this->build([], new \RuntimeException('tag service down'));
		$rules->addFolder(11, 'occ');
		foreach ([13, 17] as $id) {
			$outbox->touch($id, 'file');
		}
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind) SELECT g, 'file' FROM generate_series(2000, 2249) g");     // 250 more, so the batch is full
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$runner->runFor(30, 100);
		$max = (int)$db->rows('SELECT max(attempts) AS m FROM aps_search.outbox')[0]['m'];
		$this->assertLessThanOrEqual(1, $max, 'every row was tried once in this run, none again');
	}

	public function testBackoffGrowsWithEachAttempt(): void {
		[$db, $rules, , $p, $outbox] = $this->build([], new \RuntimeException('down'));
		$rules->addFolder(11, 'occ');
		$outbox->touch(13, 'file');
		$runner = new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class));
		$waits = [];
		for ($i = 0; $i < 3; $i++) {
			$runner->runBatch(10);
			$waits[] = (int)round((float)$db->rows('SELECT extract(epoch FROM not_before - now()) / 60 AS m FROM aps_search.outbox')[0]['m']);
			$db->execute('UPDATE aps_search.outbox SET not_before = now()');
		}
		$this->assertSame([2, 4, 8], $waits);
	}

	// ---- S3 review fixes ----
	public function testOneFailingPermissionRefreshDoesNotStopTheOthersAndIsRetried(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $m) use (&$logged): void {
			$logged[] = $m;
		});
		[$db, $rules, , $p] = $this->build([], null, self::TREE, static function (int $id): void {
			if ($id === 13) {
				throw new \RuntimeException('row with uids: u:alice u:bob');
			}
		}, $logger);
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$this->indexedRow(17);
		$db->execute("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now()");
		$this->assertSame(1, $p->refreshDirty(100), 'the healthy file was refreshed');
		$this->assertSame([17], $this->aclWritten);
		$dirty = array_map('intval', array_column($db->rows('SELECT file_id FROM aps_search.file_state WHERE acl_dirty'), 'file_id'));
		$this->assertSame([13], $dirty, 'the failing one stays marked for the next cycle');
		$this->assertNotEmpty($logged);
		$this->assertStringNotContainsString('alice', implode(' ', $logged), 'the failure is logged by class and place only');
	}

	public function testAChangeThatArrivesWhileThePrincipalsAreBeingWrittenIsNotLost(): void {
		[$db, $rules, , $p] = $this->build([], null, self::TREE, function (int $id) use (&$db): void {
			// a share event marks the file again in the middle of the write
			self::connect('postgres', 'dev', self::$dbName)->exec("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() WHERE file_id = $id");
		});
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$db->execute("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() WHERE file_id = 13");
		$p->refreshDirty(10);
		$this->assertSame(1, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n'], 'still marked: the mid-write change must be seen next time');
	}

	public function testTheNewestPermissionChangesAreRefreshedFirst(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$this->indexedRow(17);
		$db->execute("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() - interval '3 days' WHERE file_id = 13");      // the weekly sweep marked it long ago
		$db->execute("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() WHERE file_id = 17");                          // a revocation just happened
		$p->refreshDirty(1);
		$this->assertSame([17], $this->aclWritten, 'a real change is not queued behind the weekly sweep');
	}

	public function testErasedFilesAreNeverMarkedOrRefreshedForPermissions(): void {
		[$db, $rules, , $p] = $this->build();
		$rules->addFolder(10, 'occ');
		$this->indexedRow(13);
		$db->execute("UPDATE aps_search.file_state SET text_state = 'skipped', skip_reason = 'excluded' WHERE file_id = 13");
		$p->process(11, 'acl');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n'], 'an erased file is not marked');
		$db->execute("UPDATE aps_search.file_state SET acl_dirty = true, acl_dirty_since = now() WHERE file_id = 13");
		$this->assertSame(0, $p->refreshDirty(10));
		$this->assertSame([], $this->aclWritten, 'and file_acl is not re-created for it');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state WHERE acl_dirty')[0]['n'], 'the stale mark is dropped');
	}

	public function testLeavingTheScopeIsErasedEvenWhileTheTagStateIsUnavailable(): void {
		[, $rules, , $p] = $this->build([], new \RuntimeException('tag service down'));
		$rules->addFolder(11, 'occ');
		$this->indexedRow(15);                                            // under 'other', outside the rule
		$this->assertSame('erased_out_of_scope', $p->process(15, 'file'), 'removals do not need the tag service');
		$this->assertSame(0, $this->rowCount('chunk', 15));
	}

	public function testFilesNeverIndexedLeaveNoStateOrTombstoneBehind(): void {
		[$db, , , $p] = $this->build();
		$this->assertSame('ignored', $p->process(16, 'file'), 'a trash or version path that was never indexed');
		$this->assertSame('ignored', $p->process(98, 'file'), 'an id that is gone and was never indexed');
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.file_state')[0]['n']);
		$this->assertSame(0, (int)$db->rows('SELECT count(*) AS n FROM aps_search.tombstone')[0]['n']);
	}

	public function testTheScopeIsLoadedOncePerBatchNotOncePerRow(): void {
		[$db, $rules, , , $outbox] = $this->build();
		$rules->addFolder(11, 'occ');
		$spy = $this->createMock(ScopeRules::class);
		$calls = 0;
		$spy->method('current')->willReturnCallback(function () use ($rules, &$calls) {
			$calls++;
			return $rules->current();
		});
		$conn = $this->connection($this->app());
		$tree = new FileTree($conn);
		$exclusion = TreeChecker::make(self::TREE, [[3, ExclusionChecker::TAG_NAME]], []);
		$indexer = $this->createMock(FileIndexer::class);
		$indexer->method('enqueue')->willReturn(true);
		$p = new Processor($db, $tree, $spy, new ScopePredicate($tree), $exclusion, $indexer, $this->createMock(PrincipalWriter::class), new Settings($db), $this->createMock(LoggerInterface::class));
		foreach ([13, 17] as $id) {
			$outbox->touch($id, 'file');
		}
		$db->execute("INSERT INTO aps_search.outbox (file_id, kind) VALUES (15, 'file'), (21, 'file')");
		(new OutboxRunner($db, $outbox, $p, $this->createMock(LoggerInterface::class)))->runBatch(50);
		$this->assertSame(1, $calls, 'four rows, one load of the rules and the settings');
	}

	public function testAFailingOutboxInsertDoesNotPoisonTheRequestsOwnTransaction(): void {
		$pdo = $this->app();
		$conn = $this->connection($pdo);
		$outbox = new Outbox(new IndexDb($conn));
		$pdo->beginTransaction();
		try {
			$outbox->touch(5, 'nonsense');                                // violates the kind check
			$this->fail('the failure itself is still reported to the caller');
		} catch (\PDOException) {
			$this->addToAssertionCount(1);
		}
		$pdo->query('SELECT 1');                                          // without a savepoint: "current transaction is aborted"
		$outbox->touch(6, 'file');
		$pdo->commit();
		$this->assertSame(1, $this->rowCount('outbox', 6));
	}
}
