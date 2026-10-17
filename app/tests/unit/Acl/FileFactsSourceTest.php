<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Acl;

use OCA\BuscadorDocumental\Acl\FileFactsSource;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FileFactsSourceTest extends TestCase {
	/** @param list<array<string, mixed>> $shares */
	private function source(string $path, array $shares, int|false $gfAcl = false): FileFactsSource {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(function (string $sql) use ($path, $shares, $gfAcl): IResult {
			$r = $this->createMock(IResult::class);
			if (str_contains($sql, 'FROM *PREFIX*filecache f')) {
				$r->method('fetch')->willReturn(['fileid' => 5, 'name' => 'a.pdf', 'path' => $path, 'etag' => 'e', 'mtime' => 1, 'parent' => 0, 'mimetype' => 'application/pdf', 'storage_id' => 'home::alice']);
			} elseif (str_contains($sql, '*PREFIX*share')) {
				$r->method('fetchAll')->willReturn($shares);
			} elseif (str_contains($sql, 'group_folders_groups')) {
				$r->method('fetchAll')->willReturn([['group_id' => 'med']]);
			} elseif (str_contains($sql, 'FROM *PREFIX*group_folders ')) {
				$r->method('fetchOne')->willReturn($gfAcl);
			}
			return $r;
		});
		return new FileFactsSource($db);
	}

	public function testUserAndGroupSharesAreModelled(): void {
		$f = $this->source('files/a.pdf', [['share_type' => 0, 'share_with' => 'bob'], ['share_type' => 1, 'share_with' => 'staff']])->load(5);
		$this->assertSame(['bob'], $f->shareUserIds);
		$this->assertSame(['staff'], $f->shareGroupIds);
		$this->assertFalse($f->unmodelledAccess);
	}

	/** @dataProvider unmodelledTypes */
	public function testCircleTalkDeckMailAndFederatedSharesAreUnmodelled(int $type): void {
		$f = $this->source('files/a.pdf', [['share_type' => $type, 'share_with' => 'x']])->load(5);
		$this->assertTrue($f->unmodelledAccess);
		$this->assertSame([], $f->shareUserIds);
	}

	public static function unmodelledTypes(): array {
		return [[4], [6], [7], [8], [9], [10], [11], [12], [13], [15]];
	}

	/** @dataProvider internalTypes */
	public function testLinkSharesAndPerMemberGroupRowsAreNotUnmodelledAccess(int $type): void {
		$f = $this->source('files/a.pdf', [['share_type' => 1, 'share_with' => 'staff'], ['share_type' => $type, 'share_with' => 'bob']])->load(5);
		$this->assertFalse($f->unmodelledAccess);
		$this->assertSame(['staff'], $f->shareGroupIds);
	}

	public static function internalTypes(): array {
		// 2 = the per-member row Nextcloud creates under every group share (an override of the group share, not new access);
		// 3 = anonymous link: search needs a logged-in user, so a link grants nobody a hit.
		return [[2], [3]];
	}

	public function testGroupfolderWithAdvancedAclIsUnmodelled(): void {
		$f = $this->source('__groupfolders/3/x/a.pdf', [], 1)->load(5);
		$this->assertSame(['med'], $f->groupfolderGroupIds);
		$this->assertTrue($f->unmodelledAccess);
	}

	public function testGroupfolderWithoutAdvancedAclIsModelled(): void {
		$this->assertFalse($this->source('__groupfolders/3/x/a.pdf', [], 0)->load(5)->unmodelledAccess);
	}
}
