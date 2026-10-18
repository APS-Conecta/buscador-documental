<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Status;

/** The status snapshot as Prometheus text: numbers and a few fixed labels, never a name, a path, a profile name or any text. */
final class Prometheus {
	/** @param array<string, mixed> $s a StatusReport snapshot */
	public static function render(array $s): string {
		$m = [];
		$add = static function (string $name, string $help, array $samples) use (&$m): void {
			if ($samples !== []) {
				$m[$name] = [$help, $samples];
			}
		};
		$add('buscador_jobs_queued', 'Jobs waiting for a worker', [['', $s['jobs']['queued']]]);
		$add('buscador_jobs_claimed', 'Jobs a worker is working on', [['', $s['jobs']['claimed']]]);
		$add('buscador_paused', '1 when reading is paused', [['', $s['paused'] ? 1 : 0]]);
		$files = [];
		foreach ($s['states'] as $state => $n) {
			$files[] = [self::label('state', (string)$state), $n];
		}
		$add('buscador_files', 'Files by text state', $files);
		$skipped = [];
		foreach ($s['skipped'] as $reason => $n) {
			$skipped[] = [self::label('reason', (string)$reason), $n];
		}
		$add('buscador_skipped', 'Skipped files by reason code', $skipped);
		$add('buscador_outbox_pending', 'Events not processed yet', [['', $s['outbox']['pending']]]);
		$add('buscador_outbox_parked', 'Events stopped after repeated failures', [['', $s['outbox']['parked']]]);
		$add('buscador_acl_backlog_seconds', 'Age of the oldest pending permission change', $s['acl_backlog_seconds'] === null ? [] : [['', $s['acl_backlog_seconds']]]);
		$add('buscador_last_reconcile_age_seconds', 'Seconds since the last finished reconciliation', ($s['last_reconcile_age_seconds'] ?? null) === null ? [] : [['', $s['last_reconcile_age_seconds']]]);
		$cov = [];
		foreach ($s['vectors']['generations'] as $g) {
			$cov[] = [self::label('gen', (string)(int)$g['gen']), self::num((float)$g['coverage'])];
		}
		$add('buscador_vector_coverage_ratio', 'Share of indexed files with vectors, per generation', $cov);
		$add('buscador_vector_queued', 'Vector jobs waiting', [['', $s['vectors']['queued']]]);
		$add('buscador_vector_failed', 'Files whose vectors the worker gave up on', [['', $s['vectors']['failed']]]);
		$eta = $opens = $rate = [];
		foreach (($s['eta'] ?? []) as $lane => $e) {
			if ($e['eta_seconds'] !== null) {
				$eta[] = [self::label('lane', (string)$lane), $e['eta_seconds']];
			}
			if ($e['opens_in_seconds'] !== null) {
				$opens[] = [self::label('lane', (string)$lane), $e['opens_in_seconds']];
			}
			if ($e['rate_per_hour'] !== null) {
				$rate[] = [self::label('lane', (string)$lane), self::num($e['rate_per_hour'])];
			}
		}
		$add('buscador_eta_seconds', 'Estimated time to finish the queue, per lane (absent when unknown)', $eta);
		$add('buscador_window_opens_in_seconds', 'Seconds until the night window opens, per lane (absent when open)', $opens);
		$add('buscador_rate_per_hour', 'Files completed per hour, smoothed, per lane', $rate);
		$seen = [];
		foreach (($s['workers'] ?? []) as $lane => $age) {
			$seen[] = [self::label('lane', (string)$lane), $age];
		}
		$add('buscador_worker_last_seen_age_seconds', 'Seconds since any worker last asked for work, per lane', $seen);
		$add('buscador_schema_bytes', 'Size of the search schema in the database', isset($s['schema_bytes']) ? [['', $s['schema_bytes']]] : []);

		$out = '';
		foreach ($m as $name => [$help, $samples]) {
			$out .= "# HELP $name $help\n# TYPE $name gauge\n";
			foreach ($samples as [$labels, $value]) {
				$out .= $name . $labels . ' ' . $value . "\n";
			}
		}
		return $out;
	}

	/** Only short codes become label values; anything else is dropped to a fixed word. */
	private static function label(string $key, string $value): string {
		return '{' . $key . '="' . (preg_match('/^[A-Za-z0-9_.:-]{1,40}$/', $value) === 1 ? $value : 'other') . '"}';
	}

	private static function num(float $v): string {
		return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
	}
}
