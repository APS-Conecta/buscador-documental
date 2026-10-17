<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Scale benchmark for the nightly reconciliation (S3 phase 6). Run inside the Nextcloud container as www-data after
 * spikes/s3/bench/scale.sh has loaded synthetic files: runs the REAL Reconciler to completion, timing each phase, then
 * EXPLAIN (ANALYZE, BUFFERS) of one window of each pass on the statements the reconciler itself exposes. Prints JSON.
 */

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Reconcile\Reconciler;
use OCP\IDBConnection;
use OCP\Server;

require_once '/var/www/html/lib/base.php';

$reconciler = Server::get(Reconciler::class);
$db = Server::get(IndexDb::class);
$conn = Server::get(IDBConnection::class);
$today = date('Y-m-d');

$db->execute('DELETE FROM aps_search.reconcile_node');
$db->execute('DELETE FROM aps_search.reconcile_run');
$db->execute("DELETE FROM aps_search.outbox");

$phases = [];
$steps = 0;
$t0 = microtime(true);
do {
	$before = $db->rows('SELECT phase FROM aps_search.reconcile_run WHERE finished_at IS NULL ORDER BY run_id DESC LIMIT 1');
	$phase = $before === [] ? 'eligible' : (string)$before[0]['phase'];
	$t = microtime(true);
	$out = $reconciler->runOnce(0, $today);
	$phases[$phase] = ($phases[$phase] ?? 0.0) + (microtime(true) - $t);
	$steps++;
} while (!$out['finished'] && !($out['suspended'] ?? false) && $steps < 100000);
$total = microtime(true) - $t0;

$state = $db->rows('SELECT count(*) AS n FROM aps_search.file_state')[0]['n'];
$explain = [];
$run = (int)$db->rows('SELECT max(run_id) AS r FROM aps_search.reconcile_run')[0]['r'] + 1;           // a run id nothing else uses
$explainIn = static function (string $name, string $sql, array $params, array &$explain) use ($db, $conn): void {
	$rows = $db->rows('EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) ' . $sql, $params);
	$explain[$name] = array_map(static fn (array $r): string => (string)array_values($r)[0], $rows);
};
// EXPLAIN ANALYZE executes the statement: everything below is rolled back. Materialise is explained once, then run
// for real inside the transaction so the windowed passes see a populated reconcile_node.
$conn->beginTransaction();
try {
	$s = $reconciler->statements($run, 0, 0);
	$explainIn('materialise', $s['materialise'][0], $s['materialise'][1], $explain);
	$db->execute($s['materialise'][0], $s['materialise'][1]);
	$lo = (int)$db->rows('SELECT min(file_id) AS m FROM aps_search.reconcile_node WHERE run_id = ?', [$run])[0]['m'];
	$hi = (int)$db->rows('SELECT max(file_id) AS m FROM (SELECT file_id FROM aps_search.reconcile_node WHERE run_id = ? AND file_id >= ? ORDER BY file_id LIMIT 5000) w', [$run, $lo])[0]['m'];
	foreach ($reconciler->statements($run, $lo - 1, $hi) as $name => [$sql, $params]) {
		if ($name !== 'materialise') {
			$explainIn($name, $sql, $params, $explain);
		}
	}
	$explain['window'] = ['file ids ' . $lo . '..' . $hi . ' (5000 eligible files)'];
} finally {
	$conn->rollBack();
}
$ids = ['n' => $state];
$counts = $db->rows('SELECT counts FROM aps_search.reconcile_run ORDER BY run_id DESC LIMIT 1')[0]['counts'];

echo json_encode([
	'seconds_total' => round($total, 2),
	'seconds_by_phase' => array_map(static fn (float $s): float => round($s, 2), $phases),
	'steps' => $steps,
	'counts' => json_decode((string)$counts, true),
	'file_state_rows' => (int)$ids['n'],
	'explain' => $explain,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
