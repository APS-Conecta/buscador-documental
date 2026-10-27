<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\Retrieve;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\ScriptedRecheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use OCP\Files\Node;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetrieveTest extends TestCase {
	private function node(string $name): Node {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn(true);
		$n->method('getName')->willReturn($name);
		return $n;
	}

	/** @param list<array<string, mixed>> $candidates @param list<array<string, mixed>> $headlines */
	private function db(array $candidates, array $headlines = [], ?\Throwable $fails = null): IndexDb {
		$db = $this->createMock(IndexDb::class);
		$db->method('withTimeout')->willReturnCallback(static fn (int $ms, callable $work) => $work());
		$db->method('rows')->willReturnCallback(static function (string $sql) use ($candidates, $headlines, $fails): array {
			if ($fails !== null) {
				throw $fails;
			}
			return str_contains($sql, 'ts_headline') ? $headlines : $candidates;
		});
		return $db;
	}

	private function retrieve(IndexDb $db, ScriptedRecheck $recheck, ?LoggerInterface $log = null): Retrieve {
		$principals = $this->createMock(PrincipalResolver::class);
		$principals->method('forUser')->willReturn(['u:b', 'x:recheck', 'g:staff']);
		return new Retrieve($db, $principals, $recheck, $log ?? $this->createMock(LoggerInterface::class));
	}

	private function checker(): TreeChecker {
		return TreeChecker::make([10 => 1, 11 => 1, 1 => -1], [[3, 'rag-excluded']], []);
	}

	public function testSnippetOfAChangedFileIsNotServed(): void {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn(true);
		$n->method('getName')->willReturn('a.pdf');
		$n->method('getEtag')->willReturn('new');
		$recheck = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$db = $this->db([['file_id' => 10, 'chunk_id' => 7, 'etag' => 'old']], [['chunk_id' => 7, 'page_no' => 2, 'heading' => 'H', 'snippet' => 'texto viejo']]);

		$out = $this->retrieve($db, $recheck)->run('b', 'texto', 10);

		$this->assertSame([['file_id' => 10, 'title' => 'a.pdf']], $out['hits']);
	}

	public function testDownloadDisabledHitIsTitleOnlyInFull(): void {
		$n = $this->node('contrato.pdf');
		$recheck = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$attrs = $this->createMock(IAttributes::class);
		$attrs->method('getAttribute')->willReturn(false);
		$share = $this->createMock(IShare::class);
		$share->method('getAttributes')->willReturn($attrs);
		$share->method('getExpirationDate')->willReturn(null);
		$recheck->behindShare($n, $share);

		$out = $this->retrieve($this->db([['file_id' => 10, 'chunk_id' => 500]], [['chunk_id' => 500, 'page_no' => 3, 'heading' => 'Cláusula', 'snippet' => 'secreto']]), $recheck)->run('b', 'secreto', 10);

		$this->assertSame(['status' => 'ok', 'message' => '', 'hits' => [['file_id' => 10, 'title' => 'contrato.pdf']]], $out);
		$this->assertStringNotContainsString('secreto', (string)json_encode($out));
	}

	public function testAllowedHitCarriesEscapedSnippetWithMarks(): void {
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf')]], $this->checker());
		$db = $this->createMock(IndexDb::class);
		$db->method('withTimeout')->willReturnCallback(static fn (int $ms, callable $work) => $work());
		$mark = null;
		$db->method('rows')->willReturnCallback(function (string $sql, array $p = []) use (&$mark): array {
			if (!str_contains($sql, 'ts_headline')) {
				return [['file_id' => 10, 'chunk_id' => 7]];
			}
			preg_match('/StartSel=(\[\[[0-9a-f]+:b\]\])/', $sql, $m);
			$open = $m[1];
			$close = str_replace(':b]]', ':e]]', $open);
			return [['chunk_id' => 7, 'page_no' => 2, 'heading' => null, 'snippet' => "x <script>alert(1)</script> {$open}glucosa{$close}"]];
		});

		$out = $this->retrieve($db, $recheck)->run('b', 'glucosa', 10);

		$this->assertSame([[
			'file_id' => 10,
			'title' => 'a.pdf',
			'heading' => null,
			'page_no' => 2,
			'snippet' => 'x &lt;script&gt;alert(1)&lt;/script&gt; <mark>glucosa</mark>',
		]], $out['hits']);
	}

	public function testCallerSetWithRecheckPrincipalReachesTheSql(): void {
		$seen = null;
		$db = $this->createMock(IndexDb::class);
		$db->method('withTimeout')->willReturnCallback(static fn (int $ms, callable $work) => $work());
		$db->method('rows')->willReturnCallback(static function (string $sql, array $p = []) use (&$seen): array {
			$seen = $p;
			return [];
		});
		$this->retrieve($db, ScriptedRecheck::make([], $this->checker()))->run('b', 'q', 5);
		$this->assertSame(IndexDb::textArray(['u:b', 'x:recheck', 'g:staff']), $seen[1]);
	}

	public function testDroppedCandidatesAreRefilledFromTheNextBatch(): void {
		$recheck = ScriptedRecheck::make([10 => [], 11 => [$this->node('ok.pdf')]], $this->checker());
		$batches = [
			[['file_id' => 10, 'chunk_id' => 1], ['file_id' => 11, 'chunk_id' => 2]],
		];
		$db = $this->db($batches[0], [['chunk_id' => 2, 'page_no' => 1, 'heading' => null, 'snippet' => 's']]);
		$out = $this->retrieve($db, $recheck)->run('b', 'q', 1);
		$this->assertSame([11], array_column($out['hits'], 'file_id'));
	}

	public function testStatementTimeoutIsAnExplicitTooManyResult(): void {
		$out = $this->retrieve($this->db([], [], new \RuntimeException('ERROR: canceling statement due to statement timeout')), ScriptedRecheck::make([], $this->checker()))->run('b', 'de', 10);
		$this->assertSame('too_many', $out['status']);
		$this->assertStringContainsString('Demasiados resultados', $out['message']);
		$this->assertSame([], $out['hits']);
	}

	public function testPostgresDownIsAClearErrorWithNoHits(): void {
		$out = $this->retrieve($this->db([], [], new \RuntimeException('connection refused')), ScriptedRecheck::make([], $this->checker()))->run('b', 'q', 10);
		$this->assertSame('unavailable', $out['status']);
		$this->assertSame([], $out['hits']);
	}

	public function testExclusionFailureRefusesAllHitsAndWarnsTheAdmin(): void {
		$checker = TreeChecker::make([10 => 1, 1 => -1], [[3, 'rag-excluded']], [], new \RuntimeException('down')); // the tag service fails
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf')]], $checker);
		$log = $this->createMock(LoggerInterface::class);
		$log->expects($this->once())->method('warning');
		$out = $this->retrieve($this->db([['file_id' => 10, 'chunk_id' => 1]]), $recheck, $log)->run('b', 'q', 10);
		$this->assertSame('unavailable', $out['status']);
		$this->assertSame([], $out['hits']);
	}

	public function testBlankQueryReturnsNothingWithoutTouchingTheDatabase(): void {
		$db = $this->createMock(IndexDb::class);
		$db->expects($this->never())->method('rows');
		$out = $this->retrieve($db, ScriptedRecheck::make([], $this->checker()))->run('b', '   ', 10);
		$this->assertSame('ok', $out['status']);
	}

	public function testQueryIsCappedBeforeItReachesThePlanner(): void {
		$seen = [];
		$db = $this->createMock(IndexDb::class);
		$db->method('withTimeout')->willReturnCallback(static fn (int $ms, callable $work) => $work());
		$db->method('rows')->willReturnCallback(static function (string $sql, array $params) use (&$seen): array {
			$seen[] = $params[0];
			return [];
		});
		$this->retrieve($db, ScriptedRecheck::make([], $this->checker()))->run('b', str_repeat('á', 5000), 10);
		$this->assertSame(Retrieve::MAX_QUERY_CHARS, mb_strlen($seen[0]));
	}

	public function testSnippetSqlRunsUnderTheStatementTimeout(): void {
		$timed = 0;
		$db = $this->createMock(IndexDb::class);
		$db->method('withTimeout')->willReturnCallback(static function (int $ms, callable $work) use (&$timed) {
			$timed++;
			return $work();
		});
		$db->method('rows')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'ts_headline')
			? [['chunk_id' => 500, 'page_no' => 1, 'heading' => null, 'snippet' => 'x']]
			: [['file_id' => 10, 'chunk_id' => 500]]);
		$out = $this->retrieve($db, ScriptedRecheck::make([10 => [$this->node('a.pdf')]], $this->checker()))->run('b', 'x', 10);
		$this->assertSame('ok', $out['status']);
		$this->assertSame(2, $timed, 'candidates and headline are both bounded');
	}

	public function testNothingFromTheQueryOrTheErrorMessageReachesTheLog(): void {
		$sentinel = 'SENTINEL-contrato-Juan-Perez';
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $m, array $ctx = []) use (&$logged): void {
				$logged[] = $m . ' ' . json_encode($ctx);
			});
		}
		$recheck = ScriptedRecheck::make([10 => [$this->node('a.pdf')]], $this->checker());
		// the exclusion check fails with a message that (wrongly) carries text
		$db = $this->db([['file_id' => 10, 'chunk_id' => 500]]);
		$principals = $this->createMock(PrincipalResolver::class);
		$principals->method('forUser')->willReturn(['u:b']);
		$failing = $this->createMock(\OCA\BuscadorDocumental\Search\Recheck::class);
		$failing->method('filter')->willThrowException(new \OCA\BuscadorDocumental\Acl\ExclusionUnavailable("tag lookup failed for $sentinel"));
		$out = (new Retrieve($db, $principals, $failing, $logger))->run('b', $sentinel, 10);
		$this->assertSame('unavailable', $out['status']);
		$this->assertNotEmpty($logged, 'the control: the failure is logged');
		$this->assertStringNotContainsString($sentinel, implode(' ', $logged));
		$this->assertStringNotContainsString($sentinel, json_encode($out), 'and the response does not echo the query');
	}
}
