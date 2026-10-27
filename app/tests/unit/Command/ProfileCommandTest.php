<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Command;

use OCA\BuscadorDocumental\Command\Profile;
use OCA\BuscadorDocumental\Search\ProfileCatalog;
use OCA\BuscadorDocumental\Search\ProfileManager;
use OCA\BuscadorDocumental\Search\VecBackfill;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** `occ buscador:profile refresh`: the tick, then the vector index of every ready or active generation that lacks one. */
class ProfileCommandTest extends TestCase {
	/** @param array<int, string> $failed */
	private function refresh(array $failed): CommandTester {
		$backfill = $this->createMock(VecBackfill::class);
		$backfill->method('tick')->willReturn(['queued' => 0, 'coverage' => [1 => 1.0, 2 => 1.0], 'ready' => [2]]);
		$manager = $this->createMock(ProfileManager::class);
		$manager->expects($this->once())->method('buildIndexes')->willReturnCallback(static function (callable $onBuild) use ($failed): array {
			$onBuild(1);
			$onBuild(2);
			return ['built' => array_values(array_diff([1, 2], array_keys($failed))), 'failed' => $failed];
		});
		$t = new CommandTester(new Profile($manager, ProfileCatalog::shipped(), $backfill));
		$t->execute(['action' => 'refresh']);
		return $t;
	}

	public function testRefreshBuildsTheMissingIndexesAndSaysSo(): void {
		$t = $this->refresh([]);
		$this->assertSame(0, $t->getStatusCode());
		$out = $t->getDisplay();
		$this->assertStringContainsString('generation 2: building the vector index (writes continue; this can take a while)', $out);
		$this->assertStringContainsString('generation 2: vector index built', $out);
		$this->assertLessThan(strpos($out, 'generation 2: building'), strpos($out, 'made ready: 2'), 'the tick runs first');
	}

	public function testAFailedBuildIsNamedWithItsCauseAndFailsTheCommand(): void {
		$t = $this->refresh([1 => 'could not extend file: No space left on device']);
		$this->assertSame(1, $t->getStatusCode());
		$out = $t->getDisplay();
		$this->assertStringContainsString('generation 1: building the vector index', $out);
		$this->assertStringContainsString('generation 1: vector index could not be built: could not extend file: No space left on device', $out);
		$this->assertLessThan(strpos($out, 'could not be built'), strpos($out, 'generation 1: building'));
		$this->assertStringContainsString('generation 2: vector index built', $out, 'the other generation still builds');
	}
}
