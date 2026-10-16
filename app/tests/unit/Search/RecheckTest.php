<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use DateTimeImmutable;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Search\Recheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\ScriptedRecheck;
use OCA\BuscadorDocumental\Tests\unit\Search\Doubles\TreeChecker;
use OCP\Files\Node;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class RecheckTest extends TestCase {
	private const TREE = [10 => 5, 11 => 5, 12 => 6, 5 => 1, 6 => 1, 1 => -1];

	private function node(bool $readable = true, string $name = 'doc.pdf'): Node {
		$n = $this->createMock(Node::class);
		$n->method('isReadable')->willReturn($readable);
		$n->method('getName')->willReturn($name);
		return $n;
	}

	private function share(?DateTimeImmutable $expires = null, ?bool $download = null): IShare {
		$attrs = $this->createMock(IAttributes::class);
		$attrs->method('getAttribute')->willReturn($download);
		$s = $this->createMock(IShare::class);
		$s->method('getExpirationDate')->willReturn($expires);
		$s->method('getAttributes')->willReturn($attrs);
		return $s;
	}

	private function checker(array $tagged = [], array $tags = [[3, 'rag-excluded']], array $tree = self::TREE, ?\Throwable $fails = null): TreeChecker {
		return TreeChecker::make($tree, $tags, $tagged, $fails);
	}

	public function testReadableUntaggedFileSurvivesWithSnippet(): void {
		$n = $this->node();
		$r = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$out = $r->filter('b', [10]);
		$this->assertSame([10], array_keys($out));
		$this->assertTrue($out[10]->snippetAllowed);
		$this->assertSame($n, $out[10]->node);
		$this->assertSame(0, $r->dropped());
	}

	public function testExpiredShareIsDropped(): void {
		$n = $this->node();
		$r = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$r->behindShare($n, $this->share(new DateTimeImmutable('-1 minute')));
		$this->assertSame([], $r->filter('b', [10]));
		$this->assertSame(1, $r->dropped());
	}

	public function testFutureExpiryStillReadable(): void {
		$n = $this->node();
		$r = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$r->behindShare($n, $this->share(new DateTimeImmutable('+1 day')));
		$this->assertArrayHasKey(10, $r->filter('b', [10]));
	}

	public function testDownloadDisabledSurvivesTitleOnly(): void {
		$n = $this->node();
		$r = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$r->behindShare($n, $this->share(null, false));
		$out = $r->filter('b', [10]);
		$this->assertFalse($out[10]->snippetAllowed);
	}

	public function testMultiMountPrefersTheNodeThatAllowsDownload(): void {
		$locked = $this->node();
		$open = $this->node();
		$r = ScriptedRecheck::make([10 => [$locked, $open]], $this->checker());
		$r->behindShare($locked, $this->share(null, false));
		$out = $r->filter('b', [10]);
		$this->assertTrue($out[10]->snippetAllowed);
		$this->assertSame($open, $out[10]->node);
	}

	public function testMultiMountUsesAnyReadableNode(): void {
		$dead = $this->node(false);
		$expired = $this->node();
		$live = $this->node();
		$r = ScriptedRecheck::make([10 => [$dead, $expired, $live]], $this->checker());
		$r->behindShare($expired, $this->share(new DateTimeImmutable('-1 day')));
		$this->assertSame($live, $r->filter('b', [10])[10]->node);
	}

	public function testUnreadableFileReachedThroughRecheckPrincipalIsDropped(): void {
		$r = ScriptedRecheck::make([10 => [], 11 => [$this->node(false)]], $this->checker());
		$this->assertSame([], $r->filter('b', [10, 11]));
		$this->assertSame(2, $r->dropped());
	}

	public function testTagOnAncestorAboveTheViewersMountDropsTheHit(): void {
		$r = ScriptedRecheck::make([10 => [$this->node()], 12 => [$this->node()]], $this->checker(['5' => ['3']]));
		$out = $r->filter('b', [10, 12]);
		$this->assertSame([12], array_keys($out));
		$this->assertSame(1, $r->dropped());
	}

	public function testDuplicateTagNamesAllExclude(): void {
		$r = ScriptedRecheck::make([10 => [$this->node()]], $this->checker(['10' => ['4']], [[3, 'rag-excluded'], [4, 'rag-excluded']]));
		$this->assertSame([], $r->filter('b', [10]));
	}

	public function testPartialWalkDropsOnlyThatHit(): void {
		$tree = self::TREE + [20 => 77]; // 77 has no filecache row
		$r = ScriptedRecheck::make([10 => [$this->node()], 20 => [$this->node()]], $this->checker([], [[3, 'rag-excluded']], $tree));
		$out = $r->filter('b', [20, 10]);
		$this->assertSame([10], array_keys($out));
		$this->assertSame(1, $r->dropped());
	}

	public function testExceptionOnOneHitDropsOnlyThatHit(): void {
		$r = ScriptedRecheck::make([10 => new \RuntimeException('mount broke'), 11 => [$this->node()]], $this->checker());
		$this->assertSame([11], array_keys($r->filter('b', [10, 11])));
		$this->assertSame(1, $r->dropped());
	}

	public function testTagServiceFailureFailsTheWholeRecheckClosed(): void {
		$r = ScriptedRecheck::make([10 => [$this->node()]], $this->checker([], [[3, 'rag-excluded']], self::TREE, new \RuntimeException('down')));
		$this->expectException(ExclusionUnavailable::class);
		$r->filter('b', [10]);
	}

	public function testMissingExclusionTagFailsClosed(): void {
		$r = ScriptedRecheck::make([10 => [$this->node()]], $this->checker([], []));
		$this->expectException(ExclusionUnavailable::class);
		$r->filter('b', [10]);
	}

	public function testOrderFollowsTheInputAndDuplicatesCollapse(): void {
		$r = ScriptedRecheck::make([10 => [$this->node()], 11 => [$this->node()]], $this->checker());
		$this->assertSame([11, 10], array_keys($r->filter('b', [11, 10, 11])));
	}

	public function testOutcomesAreNotCarriedBetweenCalls(): void {
		$n = $this->node();
		$r = ScriptedRecheck::make([10 => [$n]], $this->checker());
		$this->assertArrayHasKey(10, $r->filter('b', [10]));
		$r->behindShare($n, $this->share(new DateTimeImmutable('-1 second')));
		$this->assertSame([], $r->filter('b', [10]));
	}

	public function testRecheckSourceNeverReadsRawPermissions(): void {
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Search/Recheck.php');
		foreach (explode("\n", $src) as $line) {
			if (str_contains($line, 'permissions')) {
				$this->assertStringContainsString('download', $line);
			}
		}
	}
}
