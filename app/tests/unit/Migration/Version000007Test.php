<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Migration;

use OCA\BuscadorDocumental\Migration\SqlScript;
use OCA\BuscadorDocumental\Migration\Version000007Date20261027120000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version000007Test extends TestCase {
	private function migrate(?int $schemaVer): array {
		$ran = [];
		$db = $this->createMock(IDBConnection::class);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn($schemaVer ?? false);
		$db->method('executeQuery')->willReturn($result);
		$db->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$ran) {
			$ran[] = $sql;
			return 0;
		});
		(new Version000007Date20261027120000($db))->changeSchema($this->createMock(IOutput::class), fn () => $this->createMock(ISchemaWrapper::class), []);
		return $ran;
	}

	public function testAnInstanceAtSchemaFiveGetsTheServerEncryptedStepAlone(): void {
		$this->assertSame(SqlScript::upgradeToV6(), $this->migrate(5));
	}

	public function testAnInstanceAtSchemaFourGetsTheS6StepFirst(): void {
		$this->assertSame([...SqlScript::upgradeToV5(), ...SqlScript::upgradeToV6()], $this->migrate(4));
	}

	public function testAFreshInstallAlreadyAtSchemaSixDoesNothing(): void {
		$this->assertSame([], $this->migrate(6));
	}

	public function testMissingSchemaMetaMeansNothingToUpgrade(): void {
		$this->assertSame([], $this->migrate(null));
	}
}
