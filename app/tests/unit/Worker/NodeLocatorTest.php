<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Worker;

use OCA\BuscadorDocumental\Worker\NodeLocator;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class NodeLocatorTest extends TestCase {
	private function mount(string $uid): ICachedMountFileInfo {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$m = $this->createMock(ICachedMountFileInfo::class);
		$m->method('getUser')->willReturn($user);
		return $m;
	}

	private function locator(array $mounts, array $folders): NodeLocator {
		$cache = $this->createMock(IUserMountCache::class);
		$cache->method('getMountsForFileId')->willReturn($mounts);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(static function (string $uid) use ($folders) {
			$f = $folders[$uid];
			if ($f instanceof \Throwable) {
				throw $f;
			}
			return $f;
		});
		return new NodeLocator($cache, $root);
	}

	private function folderWith(?File $file): Folder {
		$f = $this->createMock(Folder::class);
		$f->method('getFirstNodeById')->willReturn($file);
		return $f;
	}

	public function testALaterMountServesTheFileWhenTheFirstOneThrows(): void {
		$file = $this->createMock(File::class);
		$l = $this->locator([$this->mount('gone'), $this->mount('b')], ['gone' => new \RuntimeException('no such user'), 'b' => $this->folderWith($file)]);
		$this->assertSame($file, $l->locate(42));
	}

	public function testNothingFoundIsNull(): void {
		$l = $this->locator([$this->mount('a')], ['a' => $this->folderWith(null)]);
		$this->assertNull($l->locate(42));
	}
}
