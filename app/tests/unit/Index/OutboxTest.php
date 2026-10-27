<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Index;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Index\Outbox;
use OCP\DB\Exception;
use PHPUnit\Framework\TestCase;

/** Handing rows back retries a unique violation once, wrapped the way Nextcloud's connection reports it. */
class OutboxTest extends TestCase {
	/** OCP\DB\Exception, then the driver's exception, then the \PDOException that carries the SQLSTATE. */
	private function failure(string $sqlState): Exception {
		$pdo = new \PDOException();
		(new \ReflectionProperty($pdo, 'code'))->setValue($pdo, $sqlState);     // PDO's SQLSTATE is a string code
		return new Exception('', 0, new \RuntimeException('', 0, $pdo));
	}

	/** @param list<?\Throwable> $failures what each guarded() call throws, in order (null: it succeeds) */
	private function db(array $failures): IndexDb {
		$db = $this->createMock(IndexDb::class);
		$db->expects($this->exactly(count($failures)))->method('guarded')->willReturnCallback(static function () use (&$failures) {
			$e = array_shift($failures);
			if ($e !== null) {
				throw $e;
			}
			return null;
		});
		return $db;
	}

	private function thrown(IndexDb $db): ?\Throwable {
		try {
			(new Outbox($db))->release([1]);
			return null;
		} catch (\Throwable $e) {
			return $e;
		}
	}

	public function testAUniqueViolationFromAConcurrentTouchIsRetriedOnce(): void {
		$this->assertNull($this->thrown($this->db([$this->failure('23505'), null])));
	}

	public function testAnyOtherDatabaseErrorIsNotRetried(): void {
		$e = $this->failure('57014');
		$this->assertSame($e, $this->thrown($this->db([$e])));
	}

	public function testAUniqueViolationOnTheRetryIsReported(): void {
		$e = $this->failure('23505');
		$this->assertSame($e, $this->thrown($this->db([$this->failure('23505'), $e])));
	}
}
