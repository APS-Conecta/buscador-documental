<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Status\Reindexer;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\ScriptedRecheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use OCA\BuscadorDocumental\Ui\AdminActions;
use OCA\BuscadorDocumental\Ui\FileText;
use OCP\Files\Node;
use OCP\IGroupManager;

/** S6 phase 2: what the admin page and the Files tab are allowed to do and to see. */
class UiServicesTest extends DbTestCase {
	/** @var array<int, list<Node>> */
	private array $nodes = [];
	private bool $isAdmin = false;
	private ?string $ownerUid = 'alice';

	private function db(): IndexDb {
		return new IndexDb($this->connection($this->app()));
	}

	private function node(string $name = 'a.pdf', string $etag = 'e1', string $ownerUid = 'alice'): Node {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn(true);
		$n->method('getName')->willReturn($name);
		$n->method('getEtag')->willReturn($etag);
		$n->method('getPath')->willReturn('/alice/files/Informes/' . $name);
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn($ownerUid);
		$n->method('getOwner')->willReturn($owner);
		return $n;
	}

	private function recheck(): ScriptedRecheck {
		$tree = array_fill_keys(array_keys($this->nodes), 9000) + [9000 => -1];
		return ScriptedRecheck::make($this->nodes, TreeChecker::make($tree, [[3, 'rag-excluded']], []));
	}

	private function fileText(): FileText {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $u): bool => $this->isAdmin);
		return new FileText($this->db(), $this->recheck(), $groups, new Reindexer($this->db()));
	}

	private function indexed(int $id, string $etag = 'e1', string $text = 'texto reconocido del documento', ?float $conf = 88.0): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, '$etag', 'indexed')");
		$a->prepare('INSERT INTO aps_search.page (file_id, page_no, text, ocr_conf, source) VALUES (?, 1, ?, ?, ?)')->execute([$id, $text, $conf, $conf === null ? 'layer' : 'ocr']);
		$this->nodes[$id] ??= [$this->node('a.pdf', $etag)];
	}

	public function testTheRecognisedTextIsServedToSomeoneWhoCanReadAndDownloadTheFile(): void {
		$this->indexed(1);
		$out = $this->fileText()->text('bob', 1);
		$this->assertSame('indexed', $out['status']);
		$this->assertSame([['page_no' => 1, 'text' => 'texto reconocido del documento', 'ocr_conf' => 88.0, 'source' => 'ocr', 'uncertain' => false]], $out['pages']);
		$this->assertFalse($out['truncated']);
	}

	public function testNothingIsRevealedAboutAFileTheCallerCannotReadOrThatIsExcluded(): void {
		$this->indexed(1);
		$this->nodes[1] = [];                                                  // not readable by bob
		$this->assertNull($this->fileText()->text('bob', 1));
		$this->assertNull($this->fileText()->text('bob', 999), 'unknown id and unreadable id answer the same');
	}

	public function testADownloadDisabledShareGetsNoText(): void {
		$this->indexed(1);
		$node = $this->node();
		$this->nodes[1] = [$node];
		$attrs = $this->createMock(\OCP\Share\IAttributes::class);
		$attrs->method('getAttribute')->willReturn(false);
		$share = $this->createMock(\OCP\Share\IShare::class);
		$share->method('getAttributes')->willReturn($attrs);
		$share->method('getExpirationDate')->willReturn(null);
		$recheck = $this->recheck();
		$recheck->behindShare($node, $share);
		$groups = $this->createMock(IGroupManager::class);
		$t = new FileText($this->db(), $recheck, $groups, new Reindexer($this->db()));
		$out = $t->text('bob', 1);
		$this->assertSame(['status' => 'restricted'], $out);
	}

	public function testAChangedFileShowsPendingNotTheOldText(): void {
		$this->indexed(1, 'old');
		$this->nodes[1] = [$this->node('a.pdf', 'new')];
		$out = $this->fileText()->text('bob', 1);
		$this->assertSame('pending', $out['status']);
		$this->assertArrayNotHasKey('pages', $out);
	}

	public function testStatesOtherThanIndexedCarryNoText(): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (1, 'e1', 'skipped', 'encrypted'), (2, 'e1', 'pending', NULL)");
		$this->nodes = [1 => [$this->node()], 2 => [$this->node()], 3 => [$this->node()]];
		$this->assertSame(['status' => 'skipped', 'reason' => 'encrypted'], $this->fileText()->text('bob', 1));
		$this->assertSame(['status' => 'pending'], $this->fileText()->text('bob', 2));
		$this->assertSame(['status' => 'none'], $this->fileText()->text('bob', 3));
	}

	public function testVeryLongTextIsCutAndSaysSo(): void {
		$this->indexed(1, 'e1', str_repeat('palabra ', 80000));
		$out = $this->fileText()->text('bob', 1);
		$this->assertTrue($out['truncated']);
		$this->assertLessThanOrEqual(FileText::MAX_CHARS, array_sum(array_map(static fn (array $p): int => mb_strlen($p['text']), $out['pages'])));
	}

	public function testOnlyTheOwnerOrAnAdminCanAskForANewReading(): void {
		$this->indexed(1);
		$this->nodes[1] = [$this->node('a.pdf', 'e1', 'alice')];
		$this->assertSame('forbidden', $this->fileText()->reread('bob', 1));
		$this->assertSame('queued', $this->fileText()->reread('alice', 1));
		$this->assertSame('pending', $this->app()->query('SELECT text_state FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM aps_search.outbox WHERE file_id = 1 AND kind = 'file'")->fetchColumn());
		$this->isAdmin = true;
		$this->assertSame('queued', $this->fileText()->reread('root', 1));
		$this->nodes[1] = [];
		$this->assertSame('forbidden', $this->fileText()->reread('alice', 1), 'no access, no re-reading: same answer as no permission');
	}

	public function testRereadNeverResurrectsAFileExcludedOrOutOfScope(): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (1, 'e1', 'skipped', 'excluded'), (2, 'e1', 'skipped', 'out_of_scope')");
		$this->nodes = [1 => [$this->node()], 2 => [$this->node()]];
		$this->assertSame('forbidden', $this->fileText()->reread('alice', 1));
		$this->assertSame('forbidden', $this->fileText()->reread('alice', 2));
		$this->assertSame('excluded', $this->app()->query('SELECT skip_reason FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
	}

	public function testRetryPutsAPermanentlySkippedFileBackButNotAPolicySkip(): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (1, 'e', 'skipped', 'encrypted'), (2, 'e', 'skipped', 'excluded'), (3, 'e', 'indexed', NULL)");
		$r = new Reindexer($this->db());
		$this->assertTrue($r->retry(1));
		$this->assertFalse($r->retry(2), 'a policy skip is decided by the scope and the tags, not by a retry');
		$this->assertFalse($r->retry(3), 'only skipped files are retried');
		$this->assertFalse($r->retry(99));
		$this->assertSame('pending', $this->app()->query('SELECT text_state FROM aps_search.file_state WHERE file_id = 1')->fetchColumn());
		$this->assertNull($this->app()->query('SELECT skip_reason FROM aps_search.file_state WHERE file_id = 1')->fetchColumn() ?: null);
	}

	private function actions(): AdminActions {
		$db = $this->db();
		$groups = $this->createMock(IGroupManager::class);
		return new AdminActions($db, new Settings($db), new ScopeRules($db, new AuditLog($db, new Pseudonyms($db))), new AuditLog($db, new Pseudonyms($db)), new Reindexer($db), $this->recheck());
	}

	public function testPauseResumeAndTheNoticeAreAuditedAndTakeEffect(): void {
		$a = $this->actions();
		$a->pause('admin1');
		$this->assertTrue((new Settings($this->db()))->isPaused());
		$a->resume('admin1');
		$this->assertFalse((new Settings($this->db()))->isPaused());
		$a->acknowledgeNotice('admin1');
		$this->assertNotNull((new Settings($this->db()))->get('notice_ack_at'));
		$actions = $this->app()->query('SELECT action FROM aps_search.audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
		$this->assertSame(['pause', 'resume', 'notice.ack'], $actions);
	}

	public function testExcludingAFolderRemovesItsRuleOnlyIfItExists(): void {
		$this->app()->exec("INSERT INTO aps_search.scope_rule (kind, file_id) VALUES ('folder', 5)");
		$id = (int)$this->app()->query('SELECT id FROM aps_search.scope_rule')->fetchColumn();
		$a = $this->actions();
		$this->assertTrue($a->excludeRule($id, 'admin1'));
		$this->assertFalse($a->excludeRule($id, 'admin1'));
		$this->assertSame(0, (int)$this->app()->query('SELECT count(*) FROM aps_search.scope_rule')->fetchColumn());
		$this->assertSame('1', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'reconcile_now'")->fetchColumn(), 'the change is evaluated soon');
	}

	public function testTheSkippedListShowsNamesOnlyForFilesTheAdminCanRead(): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (1, 'e', 'skipped', 'encrypted'), (2, 'e', 'skipped', 'encrypted'), (3, 'e', 'skipped', 'corrupt'), (4, 'e', 'skipped', 'excluded')");
		$this->nodes = [1 => [$this->node('visible.pdf')], 2 => [], 3 => [$this->node('roto.docx')]];
		$list = $this->actions()->skipped(null, 0, 25);
		$this->assertSame(['encrypted' => 2, 'corrupt' => 1], $list['counts'], 'policy skips are not problems to fix');
		$byId = array_column($list['items'], null, 'file_id');
		$this->assertSame('visible.pdf', $byId[1]['name']);
		$this->assertNull($byId[2]['name'], 'a file the admin cannot read is listed without its name');
		$this->assertArrayNotHasKey(4, $byId);
		$only = $this->actions()->skipped('corrupt', 0, 25);
		$this->assertSame([3], array_column($only['items'], 'file_id'));
		$this->assertSame([], $this->actions()->skipped('not a reason', 0, 25)['items']);
	}

	public function testTheFolderFilterListsOnlyIndexedFoldersThePersonCanRead(): void {
		$this->app()->exec("INSERT INTO aps_search.scope_rule (kind, file_id) VALUES ('folder', 11), ('folder', 12), ('all_groupfolders', NULL)");
		$this->nodes = [11 => [$this->node('Informes')]];                                     // 12 is not readable by this person
		$db = $this->db();
		$list = (new \OCA\BuscadorDocumental\Ui\FolderList(new ScopeRules($db, new AuditLog($db, new Pseudonyms($db))), $this->recheck()))->forUser('alice');
		$this->assertSame([['id' => 11, 'path' => '/Informes/Informes']], $list);
	}

	public function testPolicySkipsShowNothingToRetryAndPagesCarryTheServersUncertainFlag(): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (30, 'e1', 'skipped', 'out_of_scope')");
		$this->nodes[30] = [$this->node('a.pdf')];
		$this->assertSame(['status' => 'none'], $this->fileText()->text('alice', 30));
		$this->indexed(31, 'e1', 'texto borroso', 20.0);
		$out = $this->fileText()->text('alice', 31);
		$this->assertTrue($out['pages'][0]['uncertain']);
	}

	public function testTruncationIsReportedForTooManyPagesAndTheTextIsCutInTheDatabase(): void {
		$a = $this->app();
		$this->indexed(32, 'e1', 'uno');
		$a->exec("INSERT INTO aps_search.page (file_id, page_no, text, source) SELECT 32, g, 'x', 'layer' FROM generate_series(2, 1001) g");
		$out = $this->fileText()->text('alice', 32);
		$this->assertCount(1000, $out['pages']);
		$this->assertTrue($out['truncated'], 'a 1001-page file says it is cut');
		$this->indexed(33, 'e1', str_repeat('a', FileText::MAX_CHARS + 500));
		$big = $this->fileText()->text('alice', 33);
		$this->assertTrue($big['truncated']);
		$this->assertSame(FileText::MAX_CHARS, mb_strlen($big['pages'][0]['text']));
	}

	public function testTheSkippedCountsNeedNoFileToBeLookedAt(): void {
		$this->app()->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, skip_reason) VALUES (1, 'e', 'skipped', 'encrypted'), (2, 'e', 'skipped', 'excluded')");
		$this->assertSame(['encrypted' => 1], $this->actions()->skippedCounts());
	}
}
