<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Security;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Security\SecretRotation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class SecretRotationTest extends TestCase {
	private const NOW = 1760000000;
	/** @var array<string, string> */
	private array $store = [];
	/** @var array<string, bool> */
	private array $sensitive = [];
	/** @var list<array> */
	private array $audited = [];

	private function rotation(): SecretRotation {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => $this->store[$k] ?? $d);
		$cfg->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v, bool $lazy = false, bool $sens = false): bool {
			$this->store[$k] = $v;
			$this->sensitive[$k] = $sens;
			return true;
		});
		$cfg->method('deleteKey')->willReturnCallback(function (string $a, string $k): void { unset($this->store[$k]); });
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$audit = $this->createMock(AuditLog::class);
		$audit->method('record')->willReturnCallback(function (string $actor, string $action, ?string $subject, array $detail): void { $this->audited[] = [$action, $detail]; });
		return new SecretRotation($cfg, $time, $audit);
	}

	public function testTheNewSecretIsLongRandomAndSensitiveAndTheOldOneIsKeptForTheGrace(): void {
		$this->store['worker_secret'] = 'the-old-secret-0123456789abcdefghijklmn';
		$new = $this->rotation()->rotate(24);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $new);
		$this->assertSame($new, $this->store['worker_secret']);
		$this->assertTrue($this->sensitive['worker_secret']);
		$this->assertSame('the-old-secret-0123456789abcdefghijklmn', $this->store['worker_secret_prev']);
		$this->assertTrue($this->sensitive['worker_secret_prev']);
		$this->assertSame((string)(self::NOW + 24 * 3600), $this->store['worker_secret_prev_until']);
		$this->assertSame([['secret.rotate', ['grace_hours' => 24]]], $this->audited);
		$this->assertStringNotContainsString($new, json_encode($this->audited));
	}

	public function testRotatingAgainReplacesThePreviousSecret(): void {
		$r = $this->rotation();
		$first = $r->rotate(24);
		$second = $r->rotate(1);
		$this->assertNotSame($first, $second);
		$this->assertSame($first, $this->store['worker_secret_prev']);
		$this->assertSame((string)(self::NOW + 3600), $this->store['worker_secret_prev_until']);
	}

	public function testZeroGraceKeepsNoPreviousSecretAndNothingToKeepMeansNone(): void {
		$this->store['worker_secret'] = 'the-old-secret-0123456789abcdefghijklmn';
		$this->rotation()->rotate(0);
		$this->assertArrayNotHasKey('worker_secret_prev', $this->store);
		$this->assertArrayNotHasKey('worker_secret_prev_until', $this->store);
		$this->store = [];
		$this->rotation()->rotate(24);
		$this->assertArrayNotHasKey('worker_secret_prev', $this->store, 'a first secret has no predecessor');
	}

	public function testAShortOldSecretIsNotWorthKeeping(): void {
		$this->store['worker_secret'] = 'short';
		$this->rotation()->rotate(24);
		$this->assertArrayNotHasKey('worker_secret_prev', $this->store);
	}

	public function testAdoptTakesTheSuiteSecretAndEndsAnyRotationGrace(): void {
		$this->store['worker_secret'] = 'the-old-secret-0123456789abcdefghijklmn';
		$this->store['worker_secret_prev'] = 'even-older-0123456789abcdefghij';
		$this->store['worker_secret_prev_until'] = (string)(self::NOW + 3600);
		$adopted = 'suite-secret-0123456789abcdefghijklmnop';
		$this->assertTrue($this->rotation()->adopt($adopted));
		$this->assertSame($adopted, $this->store['worker_secret']);
		$this->assertTrue($this->sensitive['worker_secret'], 'the adopted secret is stored like a rotated one: sensitive, not exported');
		$this->assertArrayNotHasKey('worker_secret_prev', $this->store, 'a grace secret from rotate-secret has no meaning once the suite owns the secret');
		$this->assertArrayNotHasKey('worker_secret_prev_until', $this->store);
		$this->assertSame([['secret.adopt', []]], $this->audited);
		$this->assertStringNotContainsString($adopted, json_encode($this->audited));
	}

	public function testAdoptingTheSecretAlreadyInUseIsANoOp(): void {
		$this->store['worker_secret'] = 'suite-secret-0123456789abcdefghijklmnop';
		$this->assertFalse($this->rotation()->adopt('suite-secret-0123456789abcdefghijklmnop'));
		$this->assertSame([], $this->audited, 'a repeated provisioning run changes and records nothing');
		$this->assertSame('suite-secret-0123456789abcdefghijklmnop', $this->store['worker_secret']);
	}

	public function testAdoptRefusesAShortSecretAndTouchesNothing(): void {
		$this->store['worker_secret'] = 'the-old-secret-0123456789abcdefghijklmn';
		try {
			$this->rotation()->adopt('short');
			$this->fail('accepted a short secret');
		} catch (OpsException $e) {
			$this->assertStringContainsString('32 characters', $e->getMessage());
		}
		$this->assertSame('the-old-secret-0123456789abcdefghijklmn', $this->store['worker_secret']);
		$this->assertSame([], $this->audited);
	}

	public function testBadGraceIsRefused(): void {
		foreach ([-1, 721] as $bad) {
			try {
				$this->rotation()->rotate($bad);
				$this->fail("accepted $bad");
			} catch (OpsException $e) {
				$this->assertStringContainsString('0 to 720', $e->getMessage());
			}
		}
		$this->assertSame([], $this->store);
	}

	public function testItCanSayWhetherAPreviousSecretIsStillValid(): void {
		$r = $this->rotation();
		$this->assertFalse($r->previousStillValid());
		$this->store['worker_secret'] = 'the-old-secret-0123456789abcdefghijklmn';
		$r->rotate(24);
		$this->assertTrue($r->previousStillValid());
		$this->store['worker_secret_prev_until'] = (string)(self::NOW - 1);
		$this->assertFalse($r->previousStillValid());
		$this->store['worker_secret_prev_until'] = 'soon';
		$this->assertFalse($r->previousStillValid());
	}
}
