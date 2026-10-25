<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\search;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\Filters;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;

/** The three candidate legs against the real schema, with three-dimensional vectors. */
class HybridPgTest extends DbTestCase {
	private const SET = ['u:b', 'x:recheck', 'g:staff'];

	private function query(): CandidateQuery {
		return new CandidateQuery(new IndexDb($this->connection($this->app())));
	}

	private function scopeArray(): string {
		return IndexDb::textArray(self::SET);
	}

	private function generation(): void {
		$app = $this->app();
		$app->exec("SELECT aps_search.create_generation(1, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', '', '', 8192)");
		$app->exec('SELECT aps_search.mark_generation_ready(1)');
		$app->exec('SELECT aps_search.activate_generation(1)');
	}

	/** @param list<string> $principals @param list<int> $ancestors */
	private function doc(int $id, array $principals, string $text, ?array $vec = null, string $mime = 'application/pdf', int $mtime = 100, array $ancestors = [1], int $chunk = 0, ?string $owner = null, ?float $conf = null): int {
		$a = $this->app();
		$a->prepare("INSERT INTO aps_search.file_acl (file_id, principals) VALUES (?, ?::text[]) ON CONFLICT DO NOTHING")->execute([$id, IndexDb::textArray($principals)]);
		$a->prepare("INSERT INTO aps_search.file_meta (file_id, name, mime, mtime, ancestors, owner) VALUES (?, ?, ?, ?, ?::bigint[], ?) ON CONFLICT DO NOTHING")
			->execute([$id, "f$id.pdf", $mime, $mtime, IndexDb::textArray(array_map('strval', $ancestors)), $owner]);
		$a->prepare('INSERT INTO aps_search.page (file_id, page_no, text, ocr_conf, source) VALUES (?, 1, ?, ?, ?) ON CONFLICT DO NOTHING')
			->execute([$id, $text, $conf, $conf === null ? 'layer' : 'ocr']);
		$s = $a->prepare('INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (?, 1, ?, ?) RETURNING chunk_id');
		$s->execute([$id, $chunk, $text]);
		$chunkId = (int)$s->fetchColumn();
		if ($vec !== null) {
			$a->prepare('INSERT INTO aps_search.chunk_vec_1 (chunk_id, file_id, vec) VALUES (?, ?, ?::halfvec)')->execute([$chunkId, $id, '[' . implode(',', $vec) . ']']);
		}
		return $chunkId;
	}

	/** @return list<int> */
	private function files(array $rows): array {
		return array_map(static fn (array $r): int => (int)$r['file_id'], $rows);
	}

	public function testSignificadoRanksByCosineDistanceAndKeepsOneChunkPerFile(): void {
		$this->generation();
		$this->doc(1, ['u:b'], 'cercano', [1, 0, 0]);
		$best = $this->doc(2, ['u:b'], 'medio uno', [0.7, 0.7, 0], chunk: 0);
		$this->doc(2, ['u:b'], 'medio dos', [0, 1, 0], chunk: 1);
		$this->doc(3, ['u:b'], 'lejano', [-1, 0, 0]);
		$rows = $this->query()->significado([1, 0.1, 0], 1, $this->scopeArray(), new Filters(), 10, true);
		$this->assertSame([1, 2, 3], $this->files($rows));
		$this->assertSame($best, (int)$rows[1]['chunk_id'], 'the closer of the two chunks of file 2');
	}

	public function testThePrefilterApplies(): void {
		$this->generation();
		$this->doc(1, ['u:other'], 'ajeno', [1, 0, 0]);
		$this->doc(2, ['g:staff'], 'grupo', [1, 0, 0]);
		$this->doc(3, ['x:recheck'], 'sin modelar', [1, 0, 0]);
		$q = $this->query();
		$this->assertSame([2, 3], $this->files($q->significado([1, 0, 0], 1, $this->scopeArray(), new Filters(), 10, true)));
		$this->assertSame([], $this->files($q->exacta('ajeno', [], $this->scopeArray(), new Filters(), 10)), 'keyword leg too');
	}

	public function testExactaUsesAlternativesAndRelaxesToOrWhenTheStrictPassIsThin(): void {
		$this->doc(1, ['u:b'], 'informe del Resumen Estadístico Mensual de junio');
		$this->doc(2, ['u:b'], 'hemoglobina glicosilada del paciente');
		$q = $this->query();
		$this->assertSame([], $this->files($q->exacta('REM', [], $this->scopeArray(), new Filters(), 10)));
		$this->assertSame([1], $this->files($q->exacta('REM', ['resumen estadistico mensual'], $this->scopeArray(), new Filters(), 10)));
		$this->assertSame([], $this->files($q->exacta('hemoglobina inexistente', [], $this->scopeArray(), new Filters(), 10, relax: false)), 'strict AND finds nothing');
		$this->assertSame([2], $this->files($q->exacta('hemoglobina inexistente', [], $this->scopeArray(), new Filters(), 10, relax: true)), 'OR relaxation finds it');
	}

	public function testFiltersNarrowEveryLeg(): void {
		$this->generation();
		$this->doc(1, ['u:b'], 'glucosa pdf', [1, 0, 0], 'application/pdf', 100, [1, 5]);
		$this->doc(2, ['u:b'], 'glucosa imagen', [1, 0, 0], 'image/png', 200, [1, 6]);
		$this->doc(3, ['u:b'], 'glucosa texto', [1, 0, 0], 'text/plain', 300, [1, 5, 7]);
		$q = $this->query();
		$cases = [
			'type' => [new Filters(mimes: ['application/pdf']), [1]],
			'types' => [new Filters(mimes: ['application/pdf', 'image/png']), [1, 2]],
			'from' => [new Filters(from: 150), [2, 3]],
			'to' => [new Filters(to: 150), [1]],
			'range' => [new Filters(from: 150, to: 250), [2]],
			'folder' => [new Filters(folderId: 5), [1, 3]],
			'folder and type' => [new Filters(mimes: ['text/plain'], folderId: 5), [3]],
		];
		foreach ($cases as $name => [$f, $want]) {
			$kw = $this->files($q->exacta('glucosa', [], $this->scopeArray(), $f, 10));
			$vec = $this->files($q->significado([1, 0, 0], 1, $this->scopeArray(), $f, 10, true));
			sort($kw);
			sort($vec);
			$this->assertSame($want, $kw, "keyword: $name");
			$this->assertSame($want, $vec, "vector: $name");
		}
	}

	public function testFiltersApplyBeforeThePreLimitSoARestrictiveFilterStillFindsOldMatches(): void {
		$this->doc(1, ['u:b'], 'hemoglobina antigua', null, 'application/pdf');
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_acl (file_id, principals) SELECT g, ARRAY['u:b'] FROM generate_series(100, 10150) g");
		$a->exec("INSERT INTO aps_search.file_meta (file_id, name, mime, mtime, ancestors) SELECT g, 'n', 'image/png', 1, ARRAY[1]::bigint[] FROM generate_series(100, 10150) g");
		$a->exec("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) SELECT g, 1, 0, 'hemoglobina reciente' FROM generate_series(100, 10150) g");
		$q = $this->query();
		$this->assertNotContains(1, $this->files($q->exacta('hemoglobina', [], $this->scopeArray(), new Filters(), 20000)), 'unfiltered: only the newest 10 000 chunks are ranked');
		$this->assertSame([1], $this->files($q->exacta('hemoglobina', [], $this->scopeArray(), new Filters(mimes: ['application/pdf']), 10)), 'the filter runs before the pre-limit');
	}

	public function testTheAnnPathAgreesWithTheExactPathOnASmallCorpus(): void {
		$this->generation();
		mt_srand(7);
		for ($i = 1; $i <= 60; $i++) {
			$this->doc($i, ['u:b'], "doc $i", [mt_rand(-100, 100) / 100, mt_rand(-100, 100) / 100, mt_rand(1, 100) / 100]);
		}
		$q = $this->query();
		$q->buildIndex(1);
		$this->assertTrue($q->hasAnnIndex(1));
		$exact = $this->files($q->significado([0.3, -0.2, 0.9], 1, $this->scopeArray(), new Filters(), 10, true));
		$ann = $this->files($q->significado([0.3, -0.2, 0.9], 1, $this->scopeArray(), new Filters(), 10, false));
		$this->assertCount(10, $ann);
		$this->assertGreaterThanOrEqual(9, count(array_intersect($exact, $ann)), 'ANN recall on 60 vectors');
	}

	public function testAccessibleFilesCountsStopAtTheCapAndHonourFilters(): void {
		for ($i = 1; $i <= 30; $i++) {
			$this->doc($i, $i % 2 ? ['u:b'] : ['u:z'], "t $i", null, $i <= 10 ? 'application/pdf' : 'text/plain');
		}
		$q = $this->query();
		$this->assertSame(15, $q->accessibleFiles($this->scopeArray(), new Filters(), 100));
		$this->assertSame(5, $q->accessibleFiles($this->scopeArray(), new Filters(mimes: ['application/pdf']), 100));
		$this->assertSame(4, $q->accessibleFiles($this->scopeArray(), new Filters(), 3), 'capped at limit + 1');
	}

	public function testAnUnknownGenerationIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->query()->significado([1, 0, 0], 99, $this->scopeArray(), new Filters(), 10, true);
	}

	public function testTheVectorIsInertEvenIfItWasForged(): void {
		$this->generation();
		$this->doc(1, ['u:b'], 'x', [1, 0, 0]);
		$this->expectException(\InvalidArgumentException::class);
		$this->query()->significado([1, 0, NAN], 1, $this->scopeArray(), new Filters(), 10, true);
	}

	public function testThePropiedadFilterSplitsMineFromSharedInEveryLeg(): void {
		$this->generation();
		$this->doc(1, ['u:b'], 'glucosa mia', [1, 0, 0], owner: 'b');
		$this->doc(2, ['u:b'], 'glucosa de a compartida', [1, 0, 0], owner: 'a');
		$this->doc(3, ['u:b', 'g:team'], 'glucosa de carpeta de equipo', [1, 0, 0], owner: null);
		$q = $this->query();
		foreach ([['mine', [1]], ['shared', [2, 3]]] as [$which, $want]) {
			$f = (new Filters(owner: $which))->forUser('b', 60.0);
			$kw = $this->files($q->exacta('glucosa', [], $this->scopeArray(), $f, 10));
			$vec = $this->files($q->significado([1, 0, 0], 1, $this->scopeArray(), $f, 10, true));
			sort($kw);
			sort($vec);
			$this->assertSame($want, $kw, "keyword $which");
			$this->assertSame($want, $vec, "vector $which");
		}
	}

	public function testTheUncertainFilterKeepsOnlyChunksOnLowConfidencePages(): void {
		$this->generation();
		$this->doc(1, ['u:b'], 'glucosa en pagina nitida', [1, 0, 0], conf: 95.0);
		$this->doc(2, ['u:b'], 'glucosa en pagina borrosa', [1, 0, 0], conf: 41.0);
		$this->doc(3, ['u:b'], 'glucosa con capa de texto', [1, 0, 0]);
		$q = $this->query();
		$f = (new Filters(uncertain: true))->forUser('b', 60.0);
		$this->assertSame([2], $this->files($q->exacta('glucosa', [], $this->scopeArray(), $f, 10)));
		$this->assertSame([2], $this->files($q->significado([1, 0, 0], 1, $this->scopeArray(), $f, 10, true)));
		$strict = (new Filters(uncertain: true))->forUser('b', 30.0);
		$this->assertSame([], $this->files($q->exacta('glucosa', [], $this->scopeArray(), $strict, 10)), 'the threshold is the caller\'s setting');
	}

	public function testTheExactScanCountIgnoresTheChunkLevelFilter(): void {
		$this->doc(1, ['u:b'], 'a', null, conf: 10.0, owner: 'b');
		$this->doc(2, ['u:b'], 'b', null, conf: 99.0, owner: 'b');
		$f = (new Filters(owner: 'mine', uncertain: true))->forUser('b', 60.0);
		$this->assertSame(2, $this->query()->accessibleFiles($this->scopeArray(), $f, 100), 'only the file-level part narrows the cheap count');
	}

	public function testTheRelaxationDropsVeryCommonTermsAndBothPlansAgree(): void {
		$a = $this->app();
		for ($i = 1; $i <= 60; $i++) {                                          // 'comunisimo' is in every chunk: far above the common share
			$this->doc($i, ['u:b'], $i === 7 ? 'comunisimo rarisimo' : 'comunisimo relleno ' . $i);   // vectors are optional in doc()
		}
		$a->exec('ANALYZE aps_search.chunk');
		$q = $this->query();
		$onlyRare = $this->files($q->exacta('comunisimo rarisimo', [], $this->scopeArray(), new Filters(), 100, relax: true));
		$this->assertSame([7], $onlyRare, 'the very common word is left out of the OR, so only the rare one decides');
		$broad = $this->query();
		$broad->narrowFiles = 0;                                               // the plan for readers of many files
		$this->assertSame($onlyRare, $this->files($broad->exacta('comunisimo rarisimo', [], $this->scopeArray(), new Filters(), 100, relax: true)));
		$this->assertSame($this->files($q->exacta('comunisimo', [], $this->scopeArray(), new Filters(), 100, relax: false)), $this->files($broad->exacta('comunisimo', [], $this->scopeArray(), new Filters(), 100, relax: false)));
	}
}
