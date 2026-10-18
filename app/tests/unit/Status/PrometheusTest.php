<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Status;

use OCA\BuscadorDocumental\Status\Prometheus;
use PHPUnit\Framework\TestCase;

class PrometheusTest extends TestCase {
	public static function snapshot(): array {
		return [
			'mode' => 'opt_in', 'paused' => true, 'notice_ack_at' => null,
			'jobs' => ['queued' => 12, 'claimed' => 3],
			'states' => ['indexed' => 100, 'pending' => 7, 'skipped' => 4],
			'skipped' => ['encrypted' => 2, 'corrupt' => 1],
			'outbox' => ['pending' => 5, 'parked' => 1],
			'acl_backlog_seconds' => 90,
			'last_reconcile' => ['finished_at' => '2026-10-18T03:00:00Z', 'counts' => []],
			'last_reconcile_age_seconds' => 40000,
			'vectors' => ['generations' => [['gen' => 1, 'profile' => 'bge-m3', 'state' => 'active', 'coverage' => 0.5]], 'queued' => 9, 'failed' => 2],
			'eta' => ['text' => ['state' => 'ok', 'eta_seconds' => 3600, 'rate_per_hour' => 50.0, 'remaining' => 50, 'opens_in_seconds' => null],
				'vec' => ['state' => 'window_closed', 'eta_seconds' => null, 'rate_per_hour' => null, 'remaining' => 80, 'opens_in_seconds' => 600]],
			'workers' => ['text' => 30, 'vec' => 4000],
			'schema_bytes' => 123456,
		];
	}

	public function testItRendersValidTextFormatWithHelpAndType(): void {
		$out = Prometheus::render(self::snapshot());
		$this->assertStringEndsWith("\n", $out);
		$names = [];
		foreach (explode("\n", trim($out)) as $line) {
			if (str_starts_with($line, '# HELP ') || str_starts_with($line, '# TYPE ')) {
				continue;
			}
			$this->assertMatchesRegularExpression('/^[a-z_][a-z0-9_]*(\{[a-z_]+="[A-Za-z0-9_.:-]+"(,[a-z_]+="[A-Za-z0-9_.:-]+")*\})? -?[0-9]+(\.[0-9]+)?$/', $line);
			$names[explode('{', explode(' ', $line)[0])[0]] = true;
		}
		foreach (array_keys($names) as $n) {
			$this->assertStringContainsString("# TYPE $n gauge", $out, "$n has a TYPE line");
			$this->assertStringContainsString("# HELP $n ", $out);
		}
	}

	public function testTheNumbersAreTheSnapshotsAndEtaIsAbsentWhenUnknown(): void {
		$out = Prometheus::render(self::snapshot());
		foreach (['buscador_jobs_queued 12', 'buscador_jobs_claimed 3', 'buscador_paused 1', 'buscador_outbox_parked 1', 'buscador_acl_backlog_seconds 90',
			'buscador_last_reconcile_age_seconds 40000', 'buscador_files{state="indexed"} 100', 'buscador_skipped{reason="encrypted"} 2',
			'buscador_vector_coverage_ratio{gen="1"} 0.5', 'buscador_vector_failed 2', 'buscador_eta_seconds{lane="text"} 3600',
			'buscador_worker_last_seen_age_seconds{lane="vec"} 4000', 'buscador_schema_bytes 123456', 'buscador_window_opens_in_seconds{lane="vec"} 600'] as $line) {
			$this->assertStringContainsString($line . "\n", $out, $line);
		}
		$this->assertStringNotContainsString('buscador_eta_seconds{lane="vec"}', $out, 'no ETA is not a zero ETA');
	}

	public function testNoNameOrPathCanComeOutOfIt(): void {
		$s = self::snapshot();
		$s['vectors']['generations'][0]['profile'] = 'bad"name\nx';
		$out = Prometheus::render($s);
		$this->assertStringNotContainsString('bad', $out, 'free text is never used as a label');
		$this->assertStringNotContainsString('bge-m3', $out);
	}

	public function testMissingPiecesAreSkippedNotInvented(): void {
		$s = self::snapshot();
		$s['last_reconcile_age_seconds'] = null;
		$s['acl_backlog_seconds'] = null;
		$s['workers'] = [];
		$out = Prometheus::render($s);
		$this->assertStringNotContainsString('buscador_last_reconcile_age_seconds ', $out);
		$this->assertStringNotContainsString('buscador_acl_backlog_seconds ', $out);
		$this->assertStringNotContainsString('buscador_worker_last_seen_age_seconds{', $out);
	}

	public function testALaneWithANewlineOrSpaceCannotBreakTheFormatOrDuplicateASeries(): void {
		$s = self::snapshot();
		$s['workers'] = ['text' => 3, "x\n" => 5, 'y z' => 7, "text\n" => 9];
		$out = Prometheus::render($s);
		$this->assertStringNotContainsString("\n\"", $out);
		$lines = array_filter(explode("\n", $out), static fn (string $l): bool => str_starts_with($l, 'buscador_worker_last_seen_age_seconds{'));
		$this->assertSame(count($lines), count(array_unique(array_map(static fn (string $l): string => explode(' ', $l)[0], $lines))), 'no series twice');
		foreach (explode("\n", trim($out)) as $line) {
			$this->assertTrue(str_starts_with($line, '#') || preg_match('/^[a-z_][a-z0-9_]*(\{[a-z_]+="[A-Za-z0-9_.:-]+"(,[a-z_]+="[A-Za-z0-9_.:-]+")*\})? -?[0-9]+(\.[0-9]+)?$/D', $line) === 1, $line);
		}
	}
}
