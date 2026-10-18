<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Ops;

use OCA\BuscadorDocumental\Status\Prometheus;
use OCA\BuscadorDocumental\Tests\unit\Status\PrometheusTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class AlertRulesTest extends TestCase {
	/** @return list<array<string, mixed>> */
	private function rules(): array {
		$doc = Yaml::parse((string)file_get_contents(__DIR__ . '/../../../ops/alerts.yml'));
		$this->assertIsArray($doc['groups'] ?? null);
		$rules = [];
		foreach ($doc['groups'] as $g) {
			array_push($rules, ...$g['rules']);
		}
		return $rules;
	}

	public function testEveryRuleIsCompleteAndNamed(): void {
		$names = [];
		foreach ($this->rules() as $r) {
			$this->assertMatchesRegularExpression('/^Buscador[A-Za-z]+$/', $r['alert']);
			$this->assertArrayNotHasKey($r['alert'], $names, 'unique');
			$names[$r['alert']] = true;
			$this->assertNotSame('', trim((string)$r['expr']));
			$this->assertContains($r['labels']['severity'], ['info', 'warning', 'critical']);
			$this->assertNotSame('', $r['annotations']['summary']);
			$this->assertNotSame('', $r['annotations']['runbook']);
			$this->assertMatchesRegularExpression('/^\d+[mhd]$/', (string)$r['for']);
		}
		$this->assertGreaterThanOrEqual(7, count($names));
	}

	public function testEveryMetricARuleUsesIsOneTheStatusCommandPrints(): void {
		$printed = [];
		preg_match_all('/^# TYPE (buscador_[a-z_]+) gauge$/m', Prometheus::render(PrometheusTest::snapshot()), $m);
		foreach ($m[1] as $name) {
			$printed[$name] = true;
		}
		foreach ($this->rules() as $r) {
			preg_match_all('/\bbuscador_[a-z_]+\b/', (string)$r['expr'], $used);
			foreach (array_unique($used[0]) as $metric) {
				$this->assertArrayHasKey($metric, $printed, $r['alert'] . ' uses ' . $metric . ', which `status --prometheus` does not print');
			}
		}
	}

	public function testRulesThatJoinSeriesWithDifferentLabelsSayOnNothing(): void {
		foreach ($this->rules() as $r) {
			$expr = (string)$r['expr'];
			$joins = preg_match_all('/\b(and|or|unless)\b(?!\s*(on|ignoring)\s*\()/', $expr);
			if ($joins > 0) {
				$this->assertStringContainsString('on()', $expr, $r['alert'] . ' joins series with different label sets: without on() the result is always empty');
			}
		}
	}
}
