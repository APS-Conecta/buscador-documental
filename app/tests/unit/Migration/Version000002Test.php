<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Migration;

use Closure;
use OCA\BuscadorDocumental\Migration\SqlScript;
use OCA\BuscadorDocumental\Migration\Version000002Date20261017000000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version000002Test extends TestCase {
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
		(new Version000002Date20261017000000($db))->changeSchema($this->createMock(IOutput::class), static fn () => $this->createMock(ISchemaWrapper::class), []);
		return $ran;
	}

	public function testAnInstanceAtSchemaOneGetsTheS3Step(): void {
		$this->assertSame(SqlScript::upgradeToV2(), $this->migrate(1));
	}

	public function testAFreshInstallAlreadyAtSchemaTwoDoesNothing(): void {
		$this->assertSame([], $this->migrate(2));
	}

	public function testMissingSchemaMetaMeansNothingToUpgrade(): void {
		$this->assertSame([], $this->migrate(null), 'the first migration creates everything; this one must not run before it');
	}

	public function testAFailureHalfwayRollsEverythingBackAndRethrows(): void {
		$events = [];
		$db = $this->createMock(IDBConnection::class);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(1);
		$db->method('executeQuery')->willReturn($result);
		$db->method('beginTransaction')->willReturnCallback(static function () use (&$events) {
			$events[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(static function () use (&$events) {
			$events[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(static function () use (&$events) {
			$events[] = 'rollback';
		});
		$n = 0;
		$db->method('executeStatement')->willReturnCallback(static function () use (&$n) {
			if (++$n === 3) {
				throw new \RuntimeException('boom');
			}
			return 0;
		});
		try {
			(new Version000002Date20261017000000($db))->changeSchema($this->createMock(IOutput::class), static fn () => $this->createMock(ISchemaWrapper::class), []);
			$this->fail('expected the failure to surface');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}
		$this->assertSame(['begin', 'rollback'], $events);
	}

	public function testASuccessfulUpgradeCommits(): void {
		$events = [];
		$db = $this->createMock(IDBConnection::class);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(1);
		$db->method('executeQuery')->willReturn($result);
		$db->method('beginTransaction')->willReturnCallback(static function () use (&$events) {
			$events[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(static function () use (&$events) {
			$events[] = 'commit';
		});
		(new Version000002Date20261017000000($db))->changeSchema($this->createMock(IOutput::class), static fn () => $this->createMock(ISchemaWrapper::class), []);
		$this->assertSame(['begin', 'commit'], $events);
	}
}
