<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\search;

use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\Cursor;
use OCA\BuscadorDocumental\Search\EmbedClient;
use OCA\BuscadorDocumental\Search\Glossary;
use OCA\BuscadorDocumental\Search\HitBuilder;
use OCA\BuscadorDocumental\Search\Searcher;
use OCA\BuscadorDocumental\Search\SearchRequest;
use OCA\BuscadorDocumental\Search\SnapshotStore;
use OCA\BuscadorDocumental\Search\VectorState;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\ScriptedRecheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** The whole search path on the real schema; only the Nextcloud node layer and the embedding endpoint are scripted. */
class SearcherPgTest extends DbTestCase {
	/** @var array<int, list<Node>> */
	private array $nodes = [];
	private ?array $queryVector = [1.0, 0.0, 0.0];
	private ScriptedRecheck $recheck;

	private function node(string $name, string $etag = 'e1'): Node {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn(true);
		$n->method('getName')->willReturn($name);
		$n->method('getEtag')->willReturn($etag);
		return $n;
	}

	private function db(): IndexDb {
		return new IndexDb($this->connection($this->app()));
	}

	private function boot(): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation(1, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', '', '', 8192)");
		$a->exec('SELECT aps_search.mark_generation_ready(1)');
		$a->exec('SELECT aps_search.activate_generation(1)');
		$s = new Settings($this->db());
		$s->set('semantic_enabled', '1');
		$s->set('vec_coverage', '1');
	}

	private function doc(int $id, array $principals, string $text, array $vec, string $mime = 'application/pdf', int $mtime = 100, string $etag = 'e1'): void {
		$a = $this->app();
		$a->prepare("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES (?, ?, 'indexed')")->execute([$id, $etag]);
		$a->prepare('INSERT INTO aps_search.file_acl (file_id, principals) VALUES (?, ?::text[])')->execute([$id, IndexDb::textArray($principals)]);
		$a->prepare('INSERT INTO aps_search.file_meta (file_id, name, mime, mtime, ancestors) VALUES (?, ?, ?, ?, ARRAY[1]::bigint[])')->execute([$id, "f$id.pdf", $mime, $mtime]);
		$c = $a->prepare('INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (?, 1, 0, ?) RETURNING chunk_id');
		$c->execute([$id, $text]);
		$a->prepare('INSERT INTO aps_search.chunk_vec_1 (chunk_id, file_id, vec) VALUES (?, ?, ?::halfvec)')->execute([(int)$c->fetchColumn(), $id, '[' . implode(',', $vec) . ']']);
		$this->nodes[$id] ??= [$this->node("f$id.pdf", $etag)];
	}

	private function searcher(): Searcher {
		$db = $this->db();
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$settings = new Settings($db);
		$embed = $this->createMock(EmbedClient::class);
		$embed->method('embedQuery')->willReturnCallback(fn () => $this->queryVector);
		$tree = array_fill_keys(array_keys($this->nodes), 9000) + [9000 => -1];
		$this->recheck = ScriptedRecheck::make($this->nodes, TreeChecker::make($tree, [[3, 'rag-excluded']], []));
		return new Searcher(
			$db, new PrincipalResolver($users, $groups), new CandidateQuery($db), $this->recheck, new HitBuilder($db),
			new Glossary([['key' => 'rem', 'expansion' => 'resumen estadistico mensual', 'ambiguous' => false]]),
			$embed, new VectorState($db, $settings), new SnapshotStore($db), new Cursor('secret-for-the-test'), $this->createMock(LoggerInterface::class),
		);
	}

	private function req(array $o = []): SearchRequest {
		return SearchRequest::fromArray($o + ['query' => 'hemoglobina glicosilada']);
	}

	/** @return list<int> */
	private function ids(array $out): array {
		return array_column($out['hits'], 'file_id');
	}

	public function testStalePrincipalsAndOtherPeoplesFilesNeverSurfaceInAnyMode(): void {
		$this->boot();
		$this->doc(1, ['u:b'], 'La hemoglobina glicosilada del paciente', [1, 0, 0]);
		$this->doc(2, ['u:b'], 'La hemoglobina glicosilada compartida y luego revocada', [0.9, 0.1, 0]);
		$this->doc(3, ['u:other'], 'La hemoglobina glicosilada de otra persona', [1, 0, 0]);
		$this->nodes[2] = [];                                                  // share revoked; file_acl was not touched
		foreach (['exacta', 'significado', 'mixta'] as $mode) {
			$s = $this->searcher();
			$out = $s->search('b', $this->req(['mode' => $mode]));
			$this->assertSame('ok', $out['status'], $mode);
			$this->assertSame([1], $this->ids($out), $mode);
			$this->assertGreaterThanOrEqual(1, $this->recheck->dropped(), "$mode: the stale id reached the live recheck and was dropped");
			$this->assertNull($out['notice'], $mode);
		}
	}

	public function testAHitFoundOnlyByMeaningGetsTheStartOfTheChunkAndNoHighlight(): void {
		$this->boot();
		$this->doc(1, ['u:b'], 'Valor de azúcar en la sangre del paciente en ayunas durante el control', [1, 0, 0]);
		$out = $this->searcher()->search('b', $this->req(['query' => 'glicemia elevada', 'mode' => 'significado']));
		$this->assertSame([1], $this->ids($out));
		$hit = $out['hits'][0];
		$this->assertStringContainsString('azúcar', $hit['snippet']);
		$this->assertStringNotContainsString('<mark>', $hit['snippet']);
		$this->assertSame(['file_id', 'title', 'heading', 'page_no', 'snippet'], array_keys($hit));
	}

	public function testAChangedFileGetsItsTitleOnly(): void {
		$this->boot();
		$this->doc(1, ['u:b'], 'hemoglobina glicosilada', [1, 0, 0], etag: 'indexed-etag');
		$this->nodes[1] = [$this->node('f1.pdf', 'newer-etag')];
		$out = $this->searcher()->search('b', $this->req(['mode' => 'mixta']));
		$this->assertSame([['file_id' => 1, 'title' => 'f1.pdf']], $out['hits']);
	}

	public function testTheGlossaryFindsTheLongFormFromTheAcronymAndTheWorkflowPagesThroughTheRealSnapshot(): void {
		$this->boot();
		for ($i = 1; $i <= 30; $i++) {
			$this->doc($i, ['u:b'], "informe del Resumen Estadístico Mensual número $i", [1, 0, 0], mtime: 1000 + $i);
		}
		$s = $this->searcher();
		$p1 = $s->search('b', $this->req(['query' => 'REM', 'page_size' => 25]));
		$this->assertCount(25, $p1['hits']);
		$this->assertSame(30, $p1['page']['total']);
		$p2 = $this->searcher()->search('b', $this->req(['query' => 'REM', 'cursor' => $p1['cursors']['next']]));
		$this->assertCount(5, $p2['hits']);
		$this->assertSame([], array_intersect($this->ids($p1), $this->ids($p2)));
		$this->assertCount(30, array_unique([...$this->ids($p1), ...$this->ids($p2)]));
		$newest = $this->searcher()->search('b', $this->req(['query' => 'REM', 'sort' => 'modified_desc']));
		$this->assertSame([30, 29, 28], array_slice($this->ids($newest), 0, 3));
	}

	public function testFiltersApplyInEveryMode(): void {
		$this->boot();
		$this->doc(1, ['u:b'], 'hemoglobina glicosilada pdf', [1, 0, 0], 'application/pdf', 100);
		$this->doc(2, ['u:b'], 'hemoglobina glicosilada imagen', [1, 0, 0], 'image/png', 200);
		foreach (['exacta', 'significado', 'mixta'] as $mode) {
			$out = $this->searcher()->search('b', $this->req(['mode' => $mode, 'filters' => ['types' => ['imagen'], 'modified_from' => '1970-01-01']]));
			$this->assertSame([2], $this->ids($out), $mode);
		}
	}

	public function testWhenTheModelIsDownTheKeywordResultsStillComeBack(): void {
		$this->boot();
		$this->doc(1, ['u:b'], 'hemoglobina glicosilada', [1, 0, 0]);
		$this->queryVector = null;
		$out = $this->searcher()->search('b', $this->req(['mode' => 'mixta']));
		$this->assertSame([1], $this->ids($out));
		$this->assertSame('semantic_failed', $out['notice']['code']);
	}

	public function testSemanticModesNeedTheValidationFlagAndCoverage(): void {
		$this->boot();
		$this->doc(1, ['u:b'], 'hemoglobina glicosilada', [1, 0, 0]);
		(new Settings($this->db()))->set('semantic_enabled', '0');
		$out = $this->searcher()->search('b', $this->req(['mode' => 'significado']));
		$this->assertSame('semantic_unavailable', $out['notice']['code']);
		$this->assertSame([1], $this->ids($out));
		$this->assertFalse($this->searcher()->modes()[1]['enabled']);
	}
}
