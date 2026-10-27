<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class IndexDbTest extends TestCase {
	public function testTextArrayQuotesAndEscapes(): void {
		$this->assertSame('{"u:a","g:b \"c\"","x\\\\y"}', IndexDb::textArray(['u:a', 'g:b "c"', 'x\\y']));
		$this->assertSame('{}', IndexDb::textArray([]));
	}

	/** Nextcloud sends executeQuery() outside a transaction to dbreplica; inside one it stays on the primary. */
	public function testRowsOutsideATransactionRunsInsideOneSoItReachesThePrimary(): void {
		$events = [];
		$db = $this->recordingDb($events, false);
		$rows = [['kind' => 'path']];
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturnCallback(static function () use (&$events, $rows) {
			$events[] = 'fetchAll';
			return $rows;
		});
		$db->method('executeQuery')->willReturnCallback(static function () use (&$events, $result) {
			$events[] = 'executeQuery';
			return $result;
		});

		$this->assertSame($rows, (new IndexDb($db))->rows('DELETE FROM aps_search.scope_rule WHERE id = ? RETURNING kind', [7]));
		$this->assertSame(['begin', 'executeQuery', 'fetchAll', 'commit'], $events);
	}

	/** A transaction left open on Nextcloud's shared connection would swallow every later write in the request. */
	public function testRowsRollsBackAndRethrowsWhenTheStatementFails(): void {
		$events = [];
		$db = $this->recordingDb($events, false);
		$failure = new \RuntimeException('cannot execute DELETE in a read-only transaction');
		$db->method('executeQuery')->willThrowException($failure);

		try {
			(new IndexDb($db))->rows('DELETE FROM aps_search.scope_rule WHERE id = ? RETURNING kind', [7]);
			$this->fail('expected the failure to surface');
		} catch (\RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
		$this->assertSame(['begin', 'rollback'], $events);
	}

	/**
	 * A failing statement inside the caller's transaction behaves exactly as it always has: no savepoint to roll back
	 * to, so the caller's transaction is the one that fails. It also spares the savepoint's round trips.
	 */
	public function testRowsInsideACallersTransactionOpensNoSavepoint(): void {
		$events = [];
		$db = $this->recordingDb($events, true);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$db->expects($this->once())->method('executeQuery')->willReturn($result);

		(new IndexDb($db))->rows('SELECT aps_search.claim_job(?)', ['w1']);
		$this->assertSame([], $events);
	}

	/** @param list<string> $events */
	private function recordingDb(array &$events, bool $inTransaction): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn($inTransaction);
		foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollback'] as $method => $event) {
			$db->method($method)->willReturnCallback(static function () use (&$events, $event) {
				$events[] = $event;
				return true;
			});
		}
		return $db;
	}
}
