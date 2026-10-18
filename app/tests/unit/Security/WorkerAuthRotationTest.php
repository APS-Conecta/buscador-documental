<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Security;

use OCA\BuscadorDocumental\Security\WorkerAuth;
use OCA\BuscadorDocumental\Security\WorkerAuthException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

class WorkerAuthRotationTest extends TestCase {
	private const NEW = 'the-new-secret-0123456789abcdefghijklmn';
	private const OLD = 'the-old-secret-0123456789abcdefghijklmn';
	private const NOW = 1760000000;
	private int $n = 0;

	/** @param array<string, string> $store */
	private function auth(array $store): WorkerAuth {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $store[$k] ?? $d);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturn(true);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		return new WorkerAuth($config, $factory, $time);
	}

	private function request(string $secret): array {
		$nonce = 'nonce-' . str_pad((string)++$this->n, 12, '0', STR_PAD_LEFT);
		$mac = WorkerAuth::sign($secret, WorkerAuth::signedString('GET', '/p', '', '', '', 'w1', '', self::NOW, $nonce, '3', '2'));
		return ['method' => 'GET', 'path' => '/p', 'query' => '', 'range' => '', 'ifMatch' => '', 'body' => '', 'timestamp' => (string)self::NOW,
			'nonce' => $nonce, 'jobId' => '3', 'epoch' => '2', 'authorization' => "Bearer w1.$mac"];
	}

	private function reason(callable $f): string {
		try {
			$f();
		} catch (WorkerAuthException $e) {
			return $e->getMessage();
		}
		return 'accepted';
	}

	public function testTheNewSecretWorksAtOnceAndTheOldOneInsideTheGracePeriod(): void {
		$store = ['worker_secret' => self::NEW, 'worker_secret_prev' => self::OLD, 'worker_secret_prev_until' => (string)(self::NOW + 3600)];
		$a = $this->auth($store);
		$this->assertSame('w1', $a->authenticate($this->request(self::NEW)));
		$this->assertSame('w1', $a->authenticate($this->request(self::OLD)));
	}

	public function testTheOldSecretStopsWorkingWhenTheGraceEnds(): void {
		$store = ['worker_secret' => self::NEW, 'worker_secret_prev' => self::OLD, 'worker_secret_prev_until' => (string)(self::NOW - 1)];
		$a = $this->auth($store);
		$this->assertSame('bad_mac', $this->reason(fn () => $a->authenticate($this->request(self::OLD))));
		$this->assertSame('w1', $a->authenticate($this->request(self::NEW)));
	}

	public function testAPreviousSecretWithoutAnExpiryOrTooShortIsIgnored(): void {
		foreach ([['worker_secret_prev' => self::OLD], ['worker_secret_prev' => self::OLD, 'worker_secret_prev_until' => 'soon'],
			['worker_secret_prev' => 'short', 'worker_secret_prev_until' => (string)(self::NOW + 99)], ['worker_secret_prev_until' => (string)(self::NOW + 99)]] as $extra) {
			$a = $this->auth(['worker_secret' => self::NEW] + $extra);
			$this->assertSame('bad_mac', $this->reason(fn () => $a->authenticate($this->request(self::OLD))), json_encode($extra));
		}
	}

	public function testAnUnrelatedSecretIsRefusedWhateverIsConfigured(): void {
		$a = $this->auth(['worker_secret' => self::NEW, 'worker_secret_prev' => self::OLD, 'worker_secret_prev_until' => (string)(self::NOW + 3600)]);
		$this->assertSame('bad_mac', $this->reason(fn () => $a->authenticate($this->request('some-other-secret-0123456789abcdefghijk'))));
	}

	public function testWithoutTheCurrentSecretNothingIsAcceptedNotEvenThePreviousOne(): void {
		$a = $this->auth(['worker_secret_prev' => self::OLD, 'worker_secret_prev_until' => (string)(self::NOW + 3600)]);
		$this->assertSame('secret_not_configured', $this->reason(fn () => $a->authenticate($this->request(self::OLD))));
	}
}
