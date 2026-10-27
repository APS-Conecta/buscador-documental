<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Migration;

use OCA\BuscadorDocumental\Migration\SqlScript;
use OCA\BuscadorDocumental\Migration\Version000010Date20261027220000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version000010Test extends TestCase {
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
		(new Version000010Date20261027220000($db))->changeSchema($this->createMock(IOutput::class), fn () => $this->createMock(ISchemaWrapper::class), []);
		return $ran;
	}

	public function testAnInstanceAtSchemaEightGetsTheServerEncryptedStepAlone(): void {
		$this->assertSame(SqlScript::upgradeToV9(), $this->migrate(8));
	}

	public function testAnInstanceAtSchemaSevenGetsTheSnapshotCappedStepFirst(): void {
		$this->assertSame([...SqlScript::upgradeToV8(), ...SqlScript::upgradeToV9()], $this->migrate(7));
	}

	public function testAFreshInstallAlreadyAtSchemaNineDoesNothing(): void {
		$this->assertSame([], $this->migrate(9));
	}

	public function testMissingSchemaMetaMeansNothingToUpgrade(): void {
		$this->assertSame([], $this->migrate(null));
	}
}
