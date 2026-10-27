<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Command\Pause;
use OCA\BuscadorDocumental\Command\ScopeAdd;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\FileTree;
use OCA\BuscadorDocumental\Scope\ScopeRules;
use OCA\BuscadorDocumental\Scope\Settings;
use OCP\Files\IRootFolder;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../unit/Search/Doubles/EmitterStub.php';

/** The command line is audited as the non-person actor `occ`, not as a user who carries that name. */
class CommandActorTest extends DbTestCase {
	private IndexDb $db;
	private Pseudonyms $pseudonyms;

	protected function setUp(): void {
		$this->db = new IndexDb($this->connection($this->app()));
		$this->pseudonyms = new Pseudonyms($this->db);
	}

	private function audit(): AuditLog {
		return new AuditLog($this->db, $this->pseudonyms);
	}

	/** @return list<array{string, string}> action and actor of every audit row */
	private function audited(): array {
		return array_map(static fn (array $r): array => [(string)$r['action'], (string)$r['actor']], $this->db->rows('SELECT action, actor FROM aps_search.audit_log ORDER BY id'));
	}

	private function assertNoPseudonym(): void {
		$this->assertSame(0, (int)$this->db->rows('SELECT count(*) AS n FROM aps_search.pseudonym')[0]['n'], 'the command line is not a person');
	}

	public function testPauseIsAuditedAsOcc(): void {
		$this->assertSame(0, (new CommandTester(new Pause(new Settings($this->db), $this->audit())))->execute([]));
		$this->assertSame([['pause', 'occ']], $this->audited());
		$this->assertNoPseudonym();
	}

	public function testScopeAddStoresOccAsTheRuleCreatorAndTheAuditActor(): void {
		$rules = new ScopeRules($this->db, $this->audit(), $this->pseudonyms);
		$command = new ScopeAdd($rules, $this->createMock(FileTree::class), $this->createMock(IRootFolder::class));
		$this->assertSame(0, (new CommandTester($command))->execute(['--groupfolders' => true]));
		$this->assertSame(['occ'], array_column($this->db->rows('SELECT created_by_token FROM aps_search.scope_rule'), 'created_by_token'));
		$this->assertSame([['scope.add', 'occ']], $this->audited());
		$this->assertNoPseudonym();
	}
}
