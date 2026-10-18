<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\s7;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\Filters;
use OCA\BuscadorDocumental\Search\Rrf;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;
use PDO;

/**
 * Times the database stage of a search against the S7 index, with the product's own CandidateQuery, fusion and snippet SQL.
 * (The permission recheck is a separate cost measured in S1/ADR-0010: it needs real Nextcloud nodes.)
 *
 * S7_MODE exacta|mixta|recall, S7_USER narrow|mid|admin200, S7_PAGE 25|50|100, S7_PART/S7_PARTS split the queries between
 * concurrent processes, S7_REPS repeats, S7_OUT the JSON-lines file for the latencies (ms) or the recall results.
 */
class LoadTest extends DbTestCase {
	public static function setUpBeforeClass(): void {
	}

	public static function tearDownAfterClass(): void {
	}

	private function env(string $k, string $d): string {
		return getenv($k) !== false ? (string)getenv($k) : $d;
	}

	/** @return list<string> */
	private function principals(string $user): array {
		return match ($user) {
			'narrow' => ['g:g001', 'g:g002'],                                                   // reads ~1.6 % of the files
			'mid' => array_map(static fn (int $i): string => sprintf('g:g%03d', $i), range(1, 20)),   // ~10 team folders
			default => array_map(static fn (int $i): string => sprintf('g:g%03d', $i), range(1, 200)), // administrator of every group
		};
	}

	public function testMeasure(): void {
		$pdo = new PDO('pgsql:host=' . $this->env('S7_HOST', 'db') . ';dbname=nextcloud', 'nextcloud', 'dev', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET ROLE bd_owner');
		$db = new IndexDb($this->connection($pdo));
		$cq = new CandidateQuery($db);
		$cq->efSearch = (int)$this->env('S7_EF', '200');
		$mode = $this->env('S7_MODE', 'exacta');
		$page = (int)$this->env('S7_PAGE', '25');
		$part = (int)$this->env('S7_PART', '0');
		$parts = (int)$this->env('S7_PARTS', '1');
		$reps = (int)$this->env('S7_REPS', '1');
		$set = IndexDb::textArray($this->principals($this->env('S7_USER', 'admin200')));
		$all = json_decode((string)file_get_contents($this->env('S7_QUERIES', '/spikes/s7/queries.json')), true)['queries'];
		$filters = new Filters();
		$exactFiles = 2000;
		$out = fopen($this->env('S7_OUT', '/tmp/s7.jsonl'), 'ab');
		$cap = 1000;
		foreach ($all as $i => $q) {
			if ($i % $parts !== $part) {
				continue;
			}
			for ($r = 0; $r < $reps; $r++) {
				if ($mode === 'recall') {
					// What Searcher does: readers of fewer files than the threshold get the exact scan, the others the index.
					$useExact = $cq->accessibleFiles($set, $filters, $exactFiles) < $exactFiles;
					$t = microtime(true);
					$ann = array_column($cq->significado($q['vec'], 1, $set, $filters, 10, $useExact), 'file_id');
					$ms = round((microtime(true) - $t) * 1000, 1);
					$exact = array_column($cq->significado($q['vec'], 1, $set, $filters, 10, true), 'file_id');
					fwrite($out, json_encode(['i' => $i, 'k' => count($exact), 'hit' => count(array_intersect($ann, $exact)), 'ms' => $ms, 'exact_path' => $useExact]) . "\n");
					continue;
				}
				$t = microtime(true);
				$keyword = $cq->exacta($q['q'], [], $set, $filters, $cap, $mode === 'mixta' ? false : null);
				$ranked = $keyword;
				if ($mode === 'mixta') {
					$exact = !$cq->hasAnnIndex(1) || $cq->accessibleFiles($set, $filters, $exactFiles) < $exactFiles;
					$semantic = $cq->significado($q['vec'], 1, $set, $filters, $cap, $exact);
					$ranked = Rrf::fuse([$keyword, $semantic], $cap);
				}
				$ids = array_map(static fn (array $x): int => (int)$x['chunk_id'], array_slice($ranked, 0, $page));
				if ($ids !== []) {                                                              // the snippets of the page, as HitBuilder does
					$db->rows("SELECT chunk_id, ts_headline('aps_search.spanish_unaccent'::regconfig, text, websearch_to_tsquery('aps_search.spanish_unaccent'::regconfig, ?), 'MaxWords=35, MinWords=15, MaxFragments=1') FROM aps_search.chunk WHERE chunk_id = ANY(?::bigint[])", [$q['q'], '{' . implode(',', $ids) . '}']);
				}
				fwrite($out, json_encode(['i' => $i, 'ms' => round((microtime(true) - $t) * 1000, 1), 'n' => count($ranked)]) . "\n");
			}
		}
		fclose($out);
		$this->assertTrue(true);
	}
}
