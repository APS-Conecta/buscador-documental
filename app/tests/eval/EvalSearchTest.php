<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\eval;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\Filters;
use OCA\BuscadorDocumental\Search\Glossary;
use OCA\BuscadorDocumental\Search\Rrf;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;

/**
 * `make eval-search` (A-QUAL-2, A-QUAL-3, A-QUAL-4, A-PERF-2/5 indicative). Not a unit test: it needs BD_EVAL_DIR with the
 * corpus, the gold set and the vectors computed by a real model (spikes/s4), and it writes BD_EVAL_OUT. It runs the product's
 * own SQL, glossary expansion and fusion; the only thing it does not run is the live permission recheck.
 */
class EvalSearchTest extends DbTestCase {
	private const LIMIT = 10;

	public function testEvaluate(): void {
		$dir = getenv('BD_EVAL_DIR');
		$out = getenv('BD_EVAL_OUT');
		if ($dir === false || $out === false) {
			$this->markTestSkipped('BD_EVAL_DIR and BD_EVAL_OUT are not set: this is run by `make eval-search`');
		}
		$docs = json_decode((string)file_get_contents("$dir/docs.json"), true, 512, JSON_THROW_ON_ERROR);
		$gold = json_decode((string)file_get_contents("$dir/gold.json"), true, 512, JSON_THROW_ON_ERROR);
		$vec = json_decode((string)file_get_contents("$dir/vectors.json"), true, 512, JSON_THROW_ON_ERROR);
		$timing = json_decode((string)file_get_contents("$dir/embed_timing.json"), true, 512, JSON_THROW_ON_ERROR);
		$p = $vec['profile'];
		$dim = count($vec['docs'][0]);

		$app = $this->app();
		$app->prepare('SELECT aps_search.create_generation(1, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$p['id'], $p['model'], $vec['digest'], $dim, 'f16', $p['query_prefix'], $p['passage_prefix'], $p['max_tokens']]);
		$app->exec('SELECT aps_search.mark_generation_ready(1)');
		$app->exec('SELECT aps_search.activate_generation(1)');
		$fileOf = [];
		foreach ($docs as $i => $d) {
			$id = $i + 1;
			$fileOf[$d['id']] = $id;
			$principals = $d['group'] >= 0 ? ['g:all', 'g:t' . $d['group']] : ['g:all'];
			$app->prepare("INSERT INTO aps_search.file_acl (file_id, principals) VALUES (?, ?::text[])")->execute([$id, IndexDb::textArray($principals)]);
			$app->prepare("INSERT INTO aps_search.file_meta (file_id, name, mime, mtime, ancestors) VALUES (?, ?, 'text/plain', 100, ARRAY[1]::bigint[])")->execute([$id, $d['id'] . '.txt']);
			$c = $app->prepare('INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (?, 1, 0, ?) RETURNING chunk_id');
			$c->execute([$id, $d['text']]);
			$chunk = (int)$c->fetchColumn();
			$app->prepare('INSERT INTO aps_search.chunk_vec_1 (chunk_id, file_id, vec) VALUES (?, ?, ?::halfvec)')->execute([$chunk, $id, '[' . implode(',', $vec['docs'][$i]) . ']']);
		}
		$db = new IndexDb($this->connection($app));
		$q = new CandidateQuery($db);
		$glossary = Glossary::fromFile(__DIR__ . '/../../lib/Search/glossary.json');
		$admin = IndexDb::textArray(['u:admin', 'g:all', 'x:recheck']);

		$run = function (string $mode, array $g, int $i, bool $glossaryOn = true, int $limit = self::LIMIT, ?string $set = null, bool $exact = true) use ($q, $glossary, $vec, $admin): array {
			$set ??= $admin;
			$pairs = $glossaryOn ? $glossary->pairs($g['query']) : [];
			// 'mixta' is what the product does (strict keyword leg); 'mixta_relajada' keeps the OR relaxation, for comparison.
			$kw = $mode === 'significado' ? [] : $q->exacta($g['query'], $pairs, $set, new Filters(), max($limit, 1000), $mode === 'mixta' ? false : null);
			$sem = $mode === 'exacta' ? [] : $q->significado($vec['queries'][$i], 1, $set, new Filters(), max($limit, 1000), $exact);
			$rows = match ($mode) {
				'exacta' => $kw,
				'significado' => $sem,
				default => Rrf::fuse([$kw, $sem], max($limit, 1000)),
			};
			return array_slice(array_map(static fn (array $r): int => (int)$r['file_id'], $rows), 0, $limit);
		};
		$metrics = function (array $ranked, array $relevant): array {
			$hit = 0;
			$first = null;
			$dcg = 0.0;
			foreach (array_slice($ranked, 0, self::LIMIT) as $rank => $f) {
				if (in_array($f, $relevant, true)) {
					$hit++;
					$first ??= $rank + 1;
					$dcg += 1 / log($rank + 2, 2);
				}
			}
			$ideal = 0.0;
			for ($r = 0; $r < min(count($relevant), self::LIMIT); $r++) {
				$ideal += 1 / log($r + 2, 2);
			}
			return ['recall' => $hit / count($relevant), 'rr' => $first === null ? 0.0 : 1 / $first, 'ndcg' => $ideal > 0 ? $dcg / $ideal : 0.0];
		};

		$agg = [];
		foreach (['exacta', 'exacta_sin_glosario', 'significado', 'mixta', 'mixta_relajada'] as $label) {
			$mode = $label === 'exacta_sin_glosario' ? 'exacta' : $label;
			foreach ($gold as $i => $g) {
				$relevant = array_map(static fn (string $id): int => $fileOf[$id], $g['relevant']);
				$m = $metrics($run($mode, $g, $i, $label !== 'exacta_sin_glosario'), $relevant);
				foreach (['all', $g['kind']] as $bucket) {
					foreach ($m as $k => $v) {
						$agg[$label][$bucket][$k][] = $v;
					}
				}
			}
		}
		$mean = static fn (array $xs): float => round(array_sum($xs) / max(1, count($xs)), 4);
		$table = [];
		foreach ($agg as $label => $buckets) {
			foreach ($buckets as $bucket => $ms) {
				$table[$label][$bucket] = ['recall@10' => $mean($ms['recall']), 'mrr' => $mean($ms['rr']), 'ndcg@10' => $mean($ms['ndcg']), 'queries' => count($ms['recall'])];
			}
		}

		// A-QUAL-3 (indicative): a user who can read two topics against the administrator, ANN against exact, on the semantic leg.
		$q->buildIndex(1);
		$narrow = IndexDb::textArray(['u:narrow', 'g:t3', 'g:t4', 'x:recheck']);
		$filtered = ['queries' => 0, 'ann_vs_exact_overlap' => [], 'narrow_recall_exact' => [], 'narrow_recall_ann' => [], 'admin_recall_exact' => []];
		foreach ($gold as $i => $g) {
			if (!in_array($g['group'], [3, 4], true)) {
				continue;
			}
			$relevant = array_map(static fn (string $id): int => $fileOf[$id], $g['relevant']);
			$exact = $run('significado', $g, $i, true, self::LIMIT, $narrow, true);
			$ann = $run('significado', $g, $i, true, self::LIMIT, $narrow, false);
			$filtered['queries']++;
			$filtered['ann_vs_exact_overlap'][] = count($exact) === 0 ? 1.0 : count(array_intersect($exact, $ann)) / count($exact);
			$filtered['narrow_recall_exact'][] = $metrics($exact, $relevant)['recall'];
			$filtered['narrow_recall_ann'][] = $metrics($ann, $relevant)['recall'];
			$filtered['admin_recall_exact'][] = $metrics($run('significado', $g, $i, true, self::LIMIT, $admin, true), $relevant)['recall'];
		}
		$filtered = ['queries' => $filtered['queries']] + array_map($mean, array_diff_key($filtered, ['queries' => 1]));
		// A-QUAL-3 asks that the narrow user is not served worse than the administrator by more than 5 points: positive means better.
		$filtered['narrow_ann_minus_admin_exact_points'] = round(($filtered['narrow_recall_ann'] - $filtered['admin_recall_exact']) * 100, 2);

		// Latency of the SQL stage per mode and per page size (indicative: a corpus of this size says nothing about 1M pages).
		$lat = [];
		foreach (['exacta', 'significado', 'mixta'] as $mode) {
			foreach ([25, 50, 100] as $limit) {
				$ms = [];
				foreach ($gold as $i => $g) {
					$t = microtime(true);
					$run($mode, $g, $i, true, $limit, null, false);
					$ms[] = (microtime(true) - $t) * 1000;
				}
				sort($ms);
				$lat[$mode][$limit] = ['p50_ms' => round($ms[intdiv(count($ms), 2)], 2), 'p95_ms' => round($ms[(int)(count($ms) * 0.95) - 1], 2)];
			}
		}

		$gate = [
			'hybrid_beats_both_recall' => $table['mixta']['all']['recall@10'] > max($table['exacta']['all']['recall@10'], $table['significado']['all']['recall@10']),
			'hybrid_beats_both_mrr' => $table['mixta']['all']['mrr'] > max($table['exacta']['all']['mrr'], $table['significado']['all']['mrr']),
			'semantic_beats_keyword_on_paraphrase' => $table['significado']['paraphrase']['recall@10'] > $table['exacta']['paraphrase']['recall@10'],
			'glossary_helps_acronym_queries' => $table['exacta']['acronym']['recall@10'] >= $table['exacta_sin_glosario']['acronym']['recall@10'],
			'hybrid_with_or_relaxation_beats_both_recall' => $table['mixta_relajada']['all']['recall@10'] > max($table['exacta']['all']['recall@10'], $table['significado']['all']['recall@10']),
			'filtered_shortfall_within_5_points' => -$filtered['narrow_ann_minus_admin_exact_points'] <= 5.0,
		];
		file_put_contents($out, json_encode([
			'model' => $p['model'], 'digest' => $vec['digest'], 'dim' => $dim, 'documents' => count($docs), 'queries' => count($gold),
			'embedding' => $timing, 'metrics' => $table, 'filtered' => $filtered, 'latency_sql_stage' => $lat, 'gate' => $gate,
			'note' => 'Synthetic Spanish APS corpus (spikes/s4/topics.py); indicative for quality and latency. Head-term pre-limit effect, 1M-page latency and filtered recall at scale are S7.',
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$this->assertFileExists($out);
	}
}
