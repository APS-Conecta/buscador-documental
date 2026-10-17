<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\search;

use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\Retrieve;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\ScriptedRecheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/** Retrieve against the real index schema; the Nextcloud node layer is scripted. */
class RetrievePgTest extends DbTestCase {
	private function index(int $fileId, array $principals, string $text, ?string $heading = 'Resultados', int $page = 2): void {
		$pdo = $this->app();
		$pdo->prepare('INSERT INTO aps_search.file_acl (file_id, principals) VALUES (?, ?::text[])')
			->execute([$fileId, IndexDb::textArray($principals)]);
		$pdo->prepare("INSERT INTO aps_search.file_state (file_id, text_state, etag) VALUES (?, 'indexed', 'e1') ON CONFLICT (file_id) DO NOTHING")->execute([$fileId]);
		$pdo->prepare('INSERT INTO aps_search.chunk (file_id, page_no, ord, heading, text) VALUES (?, ?, 0, ?, ?)')
			->execute([$fileId, $page, $heading, $text]);
	}

	private function node(string $name, string $etag = 'e1'): Node {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn(true);
		$n->method('getName')->willReturn($name);
		$n->method('getEtag')->willReturn($etag);
		return $n;
	}

	private function downloadDisabled(): IShare {
		$attrs = $this->createMock(IAttributes::class);
		$attrs->method('getAttribute')->willReturn(false);
		$s = $this->createMock(IShare::class);
		$s->method('getAttributes')->willReturn($attrs);
		$s->method('getExpirationDate')->willReturn(null);
		return $s;
	}

	private function retrieve(ScriptedRecheck $recheck, string $uid = 'b', array $groups = []): Retrieve {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('getUserGroupIds')->willReturn($groups);
		return new Retrieve(new IndexDb($this->connection($this->app())), new PrincipalResolver($users, $gm), $recheck, $this->createMock(LoggerInterface::class));
	}

	private function checker(): TreeChecker {
		return TreeChecker::make([10 => 1, 11 => 1, 12 => 1, 1 => -1], [[3, 'rag-excluded']], []);
	}

	public function testStalePrincipalsReachTheRecheckWhichDropsTheHit(): void {
		$this->index(10, ['u:a', 'u:b'], 'La hemoglobina glicosilada del paciente');
		$recheck = ScriptedRecheck::make([10 => []], $this->checker()); // A revoked the share; file_acl was not touched
		$stored = $this->app()->query('SELECT principals FROM aps_search.file_acl WHERE file_id = 10')->fetchColumn();
		$this->assertStringContainsString('u:b', (string)$stored);

		$out = $this->retrieve($recheck)->run('b', 'hemoglobina', 10);

		$this->assertSame('ok', $out['status']);
		$this->assertSame([], $out['hits']);
		$this->assertSame(1, $recheck->dropped(), 'the SQL stage returned the id and the recheck dropped it');
	}

	public function testChangedFileGetsTitleOnlyUntilReindexed(): void {
		$this->index(10, ['u:b'], 'La hemoglobina glicosilada del paciente');
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf', 'e2')]], $this->checker());
		$out = $this->retrieve($recheck)->run('b', 'hemoglobina', 10);
		$this->assertSame([['file_id' => 10, 'title' => 'a.pdf']], $out['hits']);
	}

	public function testPrincipalsThatDoNotMatchNeverReachTheRecheck(): void {
		$this->index(10, ['u:a'], 'La hemoglobina glicosilada del paciente');
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf')]], $this->checker());
		$out = $this->retrieve($recheck)->run('b', 'hemoglobina', 10);
		$this->assertSame([], $out['hits']);
		$this->assertSame(0, $recheck->dropped());
	}

	public function testReadableFileMissingFromFileAclIsAFalseNegativeNotALeak(): void {
		$this->app()->prepare('INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (10, 1, 0, ?)')->execute(['glucosa en ayunas']);
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf')]], $this->checker());
		$this->assertSame([], $this->retrieve($recheck)->run('b', 'glucosa', 10)['hits']);
	}

	public function testUnreadableFileCarryingTheRecheckPrincipalIsDropped(): void {
		$this->index(10, [PrincipalResolver::RECHECK], 'glucosa en ayunas');
		$recheck = ScriptedRecheck::make([10 => []], $this->checker());
		$this->assertSame([], $this->retrieve($recheck)->run('b', 'glucosa', 10)['hits']);
		$this->assertSame(1, $recheck->dropped());
	}

	public function testDownloadDisabledHitHasNoHeadingPageOrSnippet(): void {
		$this->index(10, ['u:b'], 'La hemoglobina glicosilada del paciente');
		$n = $this->node('informe.pdf');
		$recheck = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$recheck->behindShare($n, $this->downloadDisabled());

		$out = $this->retrieve($recheck)->run('b', 'hemoglobina', 10);

		$this->assertSame([['file_id' => 10, 'title' => 'informe.pdf']], $out['hits']);
		$this->assertStringNotContainsString('Resultados', (string)json_encode($out));
		$this->assertStringNotContainsString('hemoglobina', (string)json_encode($out));
	}

	public function testAllowedHitFindsAccentInsensitivelyAndHighlights(): void {
		$this->index(10, ['g:staff'], 'Plan de nutrición & dieta <b>semanal</b>');
		$recheck = ScriptedRecheck::make([10 => [$this->node('plan.pdf')]], $this->checker());

		$out = $this->retrieve($recheck, 'b', ['staff'])->run('b', 'nutricion', 10);

		$this->assertCount(1, $out['hits']);
		$hit = $out['hits'][0];
		$this->assertSame(['file_id', 'title', 'heading', 'page_no', 'snippet'], array_keys($hit));
		$this->assertSame(2, $hit['page_no']);
		$this->assertSame('Resultados', $hit['heading']);
		$this->assertStringContainsString('<mark>nutrición</mark>', $hit['snippet']);
		$this->assertStringContainsString('&amp; dieta', $hit['snippet']);
		$this->assertStringNotContainsString('<b>', $hit['snippet']);
	}

	public function testBestRankedFileFirstAndOneHitPerFile(): void {
		$this->index(10, ['u:b'], 'glucosa');
		$this->app()->prepare('INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (10, 3, 1, ?)')->execute(['otra glucosa glucosa']);
		$this->index(11, ['u:b'], 'ruido ruido ruido glucosa ruido ruido ruido ruido');
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf')], 11 => [$this->node('b.pdf')]], $this->checker());
		$out = $this->retrieve($recheck)->run('b', 'glucosa', 10);
		$this->assertSame([10, 11], array_column($out['hits'], 'file_id'));
	}

	public function testStatementTimeoutIsLocalToTheTransaction(): void {
		$db = new IndexDb($this->connection($this->app()));
		$inside = $db->withTimeout(1234, fn () => $db->rows('SHOW statement_timeout')[0]['statement_timeout']);
		$this->assertSame('1234ms', $inside);
		$this->assertNotSame('1234ms', $db->rows('SHOW statement_timeout')[0]['statement_timeout']);
	}
}
