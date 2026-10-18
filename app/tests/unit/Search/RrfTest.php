<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\Rrf;
use PHPUnit\Framework\TestCase;

class RrfTest extends TestCase {
	private function leg(array $ids): array {
		return array_map(static fn (int $f): array => ['file_id' => $f, 'chunk_id' => $f * 10], $ids);
	}

	public function testAFileInBothLegsBeatsOneInAnyLegAlone(): void {
		$out = Rrf::fuse([$this->leg([1, 2, 3]), $this->leg([3, 4, 5])], 10);
		$this->assertSame(3, $out[0]['file_id']);
	}

	public function testScoreIsTheSumOfOneOverSixtyPlusRank(): void {
		$out = Rrf::fuse([$this->leg([1, 2]), $this->leg([2, 1])], 10);
		$this->assertEqualsWithDelta(1 / 61 + 1 / 62, $out[0]['score'], 1e-12);
		$this->assertSame($out[0]['score'], $out[1]['score']);
	}

	public function testTiesBreakByFileIdSoTheOrderIsStable(): void {
		$out = Rrf::fuse([$this->leg([7, 3]), $this->leg([3, 7])], 10);
		$this->assertSame([3, 7], array_column($out, 'file_id'));
	}

	public function testTheShownChunkComesFromTheLegThatRanksTheFileBetterAndKeywordWinsTies(): void {
		$kw = [['file_id' => 1, 'chunk_id' => 11], ['file_id' => 2, 'chunk_id' => 21]];
		$vec = [['file_id' => 2, 'chunk_id' => 22], ['file_id' => 1, 'chunk_id' => 12]];
		$out = Rrf::fuse([$kw, $vec], 10);
		$byFile = array_column($out, 'chunk_id', 'file_id');
		$this->assertSame(11, $byFile[1], 'keyword ranks file 1 first');
		$this->assertSame(22, $byFile[2], 'vector ranks file 2 first');
		$tie = Rrf::fuse([[['file_id' => 5, 'chunk_id' => 51]], [['file_id' => 5, 'chunk_id' => 52]]], 10);
		$this->assertSame(51, $tie[0]['chunk_id']);
	}

	public function testOneEmptyLegOrBothAndTheLimit(): void {
		$this->assertSame([1, 2], array_column(Rrf::fuse([$this->leg([1, 2]), []], 10), 'file_id'));
		$this->assertSame([], Rrf::fuse([[], []], 10));
		$this->assertCount(2, Rrf::fuse([$this->leg([1, 2, 3, 4])], 2));
	}
}
