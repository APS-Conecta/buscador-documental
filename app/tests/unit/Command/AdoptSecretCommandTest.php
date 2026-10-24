<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Command;

use OCA\BuscadorDocumental\Command\AdoptSecret;
use OCA\BuscadorDocumental\Security\SecretRotation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AdoptSecretCommandTest extends TestCase {
	private const ENV_SECRET = 'suite-secret-0123456789abcdefghijklmnop';

	protected function tearDown(): void {
		putenv('BD_WORKER_SECRET');
	}

	private function tester(bool $adopted = true): CommandTester {
		$rotation = $this->createMock(SecretRotation::class);
		$rotation->method('adopt')->willReturn($adopted);
		return new CommandTester(new AdoptSecret($rotation));
	}

	public function testItAdoptsTheSecretFromTheEnvironmentAndNeverPrintsIt(): void {
		putenv('BD_WORKER_SECRET=' . self::ENV_SECRET);
		$t = $this->tester(adopted: true);
		$this->assertSame(0, $t->execute([]));
		$this->assertStringContainsString('BD_WORKER_SECRET', $t->getDisplay());
		$this->assertStringNotContainsString(self::ENV_SECRET, $t->getDisplay());
	}

	public function testTheSecretAlreadyInUseIsASuccessThatChangesNothing(): void {
		putenv('BD_WORKER_SECRET=' . self::ENV_SECRET);
		$t = $this->tester(adopted: false);
		$this->assertSame(0, $t->execute([]));
		$this->assertStringContainsString('already', $t->getDisplay());
		$this->assertStringNotContainsString(self::ENV_SECRET, $t->getDisplay());
	}

	public function testAMissingEnvironmentSecretIsRefusedWithTheVariableNamed(): void {
		putenv('BD_WORKER_SECRET');
		$rotation = $this->createMock(SecretRotation::class);
		$rotation->expects($this->never())->method('adopt');
		$t = new CommandTester(new AdoptSecret($rotation));
		$this->assertSame(1, $t->execute([]));
		$this->assertStringContainsString('BD_WORKER_SECRET', $t->getDisplay());
	}
}
