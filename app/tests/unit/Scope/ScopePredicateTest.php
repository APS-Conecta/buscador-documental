<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Scope;

use OCA\BuscadorDocumental\Scope\FileTree;
use OCA\BuscadorDocumental\Scope\ScopePredicate;
use OCA\BuscadorDocumental\Scope\ScopeSet;
use PHPUnit\Framework\TestCase;

/** FileTree over a scripted cache: id => [storage, path, parent]; storages: id => kind. */
final class ScriptedTree extends FileTree {
	/** @param array<int, array{0: int, 1: string, 2: int}> $nodes @param array<int, string> $kinds */
	public function __construct(private array $nodes, private array $kinds) {
	}

	public function node(int $fileId): ?array {
		$n = $this->nodes[$fileId] ?? null;
		return $n === null ? null : ['storage' => $n[0], 'path' => $n[1], 'parent' => $n[2]];
	}

	public function storageKind(int $storage): string {
		return $this->kinds[$storage] ?? 'other';
	}
}

class ScopePredicateTest extends TestCase {
	/**
	 * Home storage 1 (alice): 10 files/, 11 files/top, 12 files/top/mid, 13 files/top/mid/a.pdf, 14 files/other, 15 files/other/b.pdf,
	 * 16 trash/old.pdf, 17 files_trashbin/x. Groupfolder storage 2: 20 files, 21 files/team.pdf, 22 versions/v1, 23 trash/t.
	 * External storage 3: 30 files/ext.pdf.
	 */
	private function tree(): ScriptedTree {
		return new ScriptedTree([
			10 => [1, 'files', 1], 11 => [1, 'files/top', 10], 12 => [1, 'files/top/mid', 11], 13 => [1, 'files/top/mid/a.pdf', 12],
			14 => [1, 'files/other', 10], 15 => [1, 'files/other/b.pdf', 14], 16 => [1, 'trash/old.pdf', 1], 17 => [1, 'files_trashbin/x', 1],
			20 => [2, 'files', 2], 21 => [2, 'files/team.pdf', 20], 22 => [2, 'versions/v1', 2], 23 => [2, 'trash/t.pdf', 2],
			30 => [3, 'files/ext.pdf', 3],
		], [1 => 'home', 2 => 'groupfolder', 3 => 'other']);
	}

	private function in(ScopeSet $set, int $id): bool {
		return (new ScopePredicate($this->tree()))->inScope($set, $id);
	}

	public function testNothingIsInScopeWithoutRules(): void {
		foreach ([13, 15, 21] as $id) {
			$this->assertFalse($this->in(new ScopeSet('opt_in', [], false), $id), "file $id");
		}
	}

	public function testAFolderRuleCoversEverythingBelowItAndNothingElse(): void {
		$set = new ScopeSet('opt_in', [11], false);
		$this->assertTrue($this->in($set, 13), 'a file two levels down');
		$this->assertTrue($this->in($set, 12), 'a subfolder');
		$this->assertTrue($this->in($set, 11), 'the rule folder itself');
		$this->assertFalse($this->in($set, 15), 'a sibling folder');
		$this->assertFalse($this->in($set, 21), 'another storage');
	}

	public function testTheGroupfolderRuleCoversEveryTeamFolderFileButNotItsTrashOrVersions(): void {
		$set = new ScopeSet('opt_in', [], true);
		$this->assertTrue($this->in($set, 21));
		$this->assertFalse($this->in($set, 22), 'versions');
		$this->assertFalse($this->in($set, 23), 'trash');
		$this->assertFalse($this->in($set, 13), 'a personal file is not covered by the team-folder rule');
	}

	public function testTrashAndVersionsAreNeverInScopeEvenUnderARootRule(): void {
		$set = new ScopeSet('opt_in', [10, 1], false);
		$this->assertFalse($this->in($set, 16));
		$this->assertFalse($this->in($set, 17));
	}

	public function testAllReadableCoversEveryIndexableStorageButNotExternalOnes(): void {
		$set = new ScopeSet('all_readable', [], false);
		$this->assertTrue($this->in($set, 13));
		$this->assertTrue($this->in($set, 21));
		$this->assertFalse($this->in($set, 30), 'external storage: no indexing guarantee in R1');
		$this->assertFalse($this->in($set, 16), 'trash');
	}

	public function testAnUnknownFileIsOutOfScope(): void {
		$this->assertFalse($this->in(new ScopeSet('all_readable', [], false), 999));
	}

	public function testAnExternalStorageIsNeverInScopeEvenWithAFolderRule(): void {
		$tree = new ScriptedTree([30 => [3, 'files/ext.pdf', 31], 31 => [3, 'files', 3]], [3 => 'other']);
		$this->assertFalse((new ScopePredicate($tree))->inScope(new ScopeSet('opt_in', [31], false), 30));
	}

	public function testAParentCycleCannotHangTheWalk(): void {
		$tree = new ScriptedTree([1 => [1, 'files/a', 2], 2 => [1, 'files/b', 1]], [1 => 'home']);
		$this->assertFalse((new ScopePredicate($tree))->inScope(new ScopeSet('opt_in', [99], false), 1));
	}
}
