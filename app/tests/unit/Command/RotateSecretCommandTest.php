<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Command;

use OCA\BuscadorDocumental\Command\RotateSecret;
use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Security\SecretRotation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RotateSecretCommandTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bd-rot-' . bin2hex(random_bytes(4));
		mkdir($this->dir, 0700);
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		foreach (glob($this->dir . '/.*') ?: [] as $f) {
			if (is_file($f)) {
				@unlink($f);
			}
		}
		@rmdir($this->dir);
	}

	private function tester(string $secret = 'N3wSecret0123456789abcdefghijklmnopqrstuvwxyz12', bool $previousValid = false, ?\Throwable $fails = null): CommandTester {
		$rotation = $this->createMock(SecretRotation::class);
		$rotation->method('previousStillValid')->willReturn($previousValid);
		$rotation->method('rotate')->willReturnCallback(function (int $g) use ($secret, $fails): string {
			if ($fails !== null) {
				throw $fails;
			}
			return $secret;
		});
		return new CommandTester(new RotateSecret($rotation));
	}

	public function testItWritesThePrivateFileAndNeverPrintsTheSecretThen(): void {
		$file = $this->dir . '/new.secret';
		$t = $this->tester();
		$this->assertSame(0, $t->execute(['--to-file' => $file, '--grace' => '24']));
		$this->assertSame("N3wSecret0123456789abcdefghijklmnopqrstuvwxyz12\n", file_get_contents($file));
		$this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
		$this->assertStringNotContainsString('N3wSecret', $t->getDisplay());
		$this->assertSame([basename($file)], array_map('basename', glob($this->dir . '/*') ?: []), 'no temporary file is left behind');
	}

	public function testABadGraceLeavesAnExistingFileAlone(): void {
		$file = $this->dir . '/keep.secret';
		file_put_contents($file, "old content\n");
		$t = $this->tester(fails: new OpsException('The grace period is a whole number of hours from 0 to 720.'));
		$this->assertSame(1, $t->execute(['--to-file' => $file, '--grace' => '9999']));
		$this->assertSame("old content\n", file_get_contents($file), 'the old file was not truncated');
		$this->assertSame(1, $this->tester()->execute(['--to-file' => $file, '--grace' => 'soon']));
		$this->assertSame("old content\n", file_get_contents($file));
	}

	public function testASymlinkIsNotFollowed(): void {
		$target = $this->dir . '/victim';
		file_put_contents($target, 'untouched');
		$link = $this->dir . '/link.secret';
		symlink($target, $link);
		$t = $this->tester();
		$this->assertSame(1, $t->execute(['--to-file' => $link]));
		$this->assertStringContainsString('symbolic link', $t->getDisplay());
		$this->assertSame('untouched', file_get_contents($target));
	}

	public function testAnUnwritableDirectoryChangesNothing(): void {
		$rotated = false;
		$rotation = $this->createMock(SecretRotation::class);
		$rotation->method('rotate')->willReturnCallback(function () use (&$rotated): string { $rotated = true; return 'x'; });
		$t = new CommandTester(new RotateSecret($rotation));
		$this->assertSame(1, $t->execute(['--to-file' => '/nonexistent-dir-for-test/new.secret']));
		$this->assertFalse($rotated, 'the secret is not rotated when there is nowhere to put it');
	}

	public function testWithoutAFileTheSecretIsPrintedOnceWithAWarningAndAnOldOneStillValidIsNoted(): void {
		$t = $this->tester(previousValid: true);
		$this->assertSame(0, $t->execute([]));
		$d = $t->getDisplay();
		$this->assertStringContainsString('N3wSecret0123456789abcdefghijklmnopqrstuvwxyz12', $d);
		$this->assertStringContainsString('not shown again', $d);
		$this->assertStringContainsString('still valid', $d);
	}
}
