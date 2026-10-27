<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Acl;

use OCA\BuscadorDocumental\Acl\FileFactsSource;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FileFactsSourceTest extends TestCase {
	/**
	 * @param list<array<string, mixed>> $shares
	 * @param list<array<string, mixed>> $grants the groupfolder's grant rows with permissions > 0
	 */
	private function source(string $path, array $shares, int|false $gfAcl = false, string $storageId = 'home::alice', array $grants = [['group_id' => 'med']]): FileFactsSource {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(function (string $sql) use ($path, $shares, $gfAcl, $storageId, $grants): IResult {
			$r = $this->createMock(IResult::class);
			if (str_contains($sql, 'FROM *PREFIX*filecache f')) {
				$r->method('fetch')->willReturn(['fileid' => 5, 'name' => 'a.pdf', 'path' => $path, 'etag' => 'e', 'mtime' => 1, 'parent' => 0, 'mimetype' => 'application/pdf', 'storage' => 42, 'storage_id' => $storageId]);
			} elseif (str_contains($sql, '*PREFIX*share')) {
				$r->method('fetchAll')->willReturn($shares);
			} elseif (str_contains($sql, 'group_folders_groups')) {
				$r->method('fetchAll')->willReturn($grants);
			} elseif (str_contains($sql, 'FROM *PREFIX*group_folders ')) {
				$r->method('fetchOne')->willReturn($gfAcl);
			}
			return $r;
		});
		return new FileFactsSource($db);
	}

	public function testAncestorsAreTheParentFolderIdsUpToTheRoot(): void {
		$parents = [5 => 4, 4 => 3, 3 => 1, 1 => -1];                       // file 5 in folder 4 in folder 3 in the storage root 1
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(function (string $sql, array $p = []) use ($parents): IResult {
			$r = $this->createMock(IResult::class);
			if (str_contains($sql, 'FROM *PREFIX*filecache f')) {
				$r->method('fetch')->willReturn(['fileid' => 5, 'name' => 'a.pdf', 'path' => 'files/x/y/a.pdf', 'etag' => 'e', 'mtime' => 1, 'parent' => 4, 'mimetype' => 'application/pdf', 'storage' => 42, 'storage_id' => 'home::alice']);
			} elseif (str_contains($sql, 'SELECT parent FROM *PREFIX*filecache')) {
				$r->method('fetchOne')->willReturn($parents[$p[0]] ?? false);
			} else {
				$r->method('fetchAll')->willReturn([]);
			}
			return $r;
		});
		$f = (new FileFactsSource($db))->load(5);
		$this->assertSame([4, 3, 1], $f->ancestors);
	}

	public function testTheStorageNumericIdIsCarriedForErasure(): void {
		$this->assertSame(42, $this->source('files/a.pdf', [])->load(5)->storageId);
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

	/** NC 34 + groupfolders 22: the folder has its own storage and its files are `files/...`; the id is in the storage id. */
	public function testFilesInAGroupfolderAreRecognisedByTheirStorage(): void {
		$f = $this->source('files/sub/a.pdf', [], 0, 'local::/var/www/html/data/__groupfolders/3/')->load(5);
		$this->assertSame(['med'], $f->groupfolderGroupIds);
		$this->assertNull($f->ownerUid, 'a team folder has no owner');
		$this->assertFalse($f->unmodelledAccess);
		$this->assertSame(['g:med'], PrincipalWriter::principalsFor($f));
	}

	/** groupfolders stores a Team grant with an empty group id (the Team id is in circle_id): the live recheck decides (ADR-0003). */
	public function testATeamOnlyGrantIsUnmodelledAndStoresNoEmptyGroup(): void {
		$f = $this->source('files/a.pdf', [], 0, 'local::/var/www/html/data/__groupfolders/7/', [['group_id' => '']])->load(5);
		$this->assertSame([], $f->groupfolderGroupIds);
		$this->assertTrue($f->unmodelledAccess);
		$this->assertSame(['x:recheck'], PrincipalWriter::principalsFor($f));
	}

	public function testAGroupAndATeamGrantGiveTheGroupAndTheCatchAll(): void {
		$f = $this->source('files/a.pdf', [], 0, 'local::/var/www/html/data/__groupfolders/7/', [['group_id' => 'med'], ['group_id' => '']])->load(5);
		$this->assertSame(['med'], $f->groupfolderGroupIds);
		$this->assertSame(['g:med', 'x:recheck'], PrincipalWriter::principalsFor($f));
	}

	public function testAGroupfolderWithAdvancedAclOnItsOwnStorageIsUnmodelled(): void {
		$f = $this->source('files/a.pdf', [], 1, 'local::/var/www/html/data/__groupfolders/12/')->load(5);
		$this->assertTrue($f->unmodelledAccess);
	}

	public function testAHomeStorageFileIsNotMistakenForAGroupfolder(): void {
		$f = $this->source('files/a.pdf', [], 0, 'home::alice')->load(5);
		$this->assertSame([], $f->groupfolderGroupIds);
		$this->assertSame('alice', $f->ownerUid);
	}

	public function testGroupfolderWithoutAdvancedAclIsModelled(): void {
		$this->assertFalse($this->source('__groupfolders/3/x/a.pdf', [], 0)->load(5)->unmodelledAccess);
	}
}
