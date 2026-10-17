<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Migration;

use OCA\BuscadorDocumental\Migration\SqlScript;
use PHPUnit\Framework\TestCase;

class SqlScriptTest extends TestCase {
	public function testSplitsOnSeparatorOnlyAndKeepsDollarBodies(): void {
		$sql = "-- header\nSELECT 1;\n-- @@\nCREATE FUNCTION f() AS \$\$ BEGIN PERFORM 1; END \$\$;\n-- @@\n-- just a comment\n-- @@\nSELECT 2;\n";
		$st = SqlScript::statements($sql);
		$this->assertCount(3, $st);
		$this->assertStringContainsString('PERFORM 1; END', $st[1]);
	}

	public function testUpStepsLoadAndPreflightComesFirst(): void {
		$up = SqlScript::up();
		$this->assertStringContainsString('bd_owner', $up[0]);
		$this->assertGreaterThan(20, count($up));
	}
}
