<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Security;

use OCA\BuscadorDocumental\Security\WorkerAuth;
use OCA\BuscadorDocumental\Security\WorkerAuthException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

class WorkerAuthTest extends TestCase {
	private const SECRET = 'unit-test-secret-0123456789abcdefghij';
	private const NOW = 1760000000;

	/** @var array<string,bool> */
	private array $seen = [];

	private function auth(?string $secret = self::SECRET, bool $cacheAvailable = true, bool $atomic = true): WorkerAuth {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($secret ?? '');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$cache = $atomic ? $this->createMock(IMemcache::class) : $this->createMock(ICache::class);
		if ($atomic) {
			$cache->method('add')->willReturnCallback(function (string $k) {
				if (isset($this->seen[$k])) {
					return false;
				}
				return $this->seen[$k] = true;
			});
		}
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cacheAvailable);
		$factory->method('createDistributed')->willReturn($cache);
		return new WorkerAuth($config, $factory, $time);
	}

	private function request(array $over = [], string $secret = self::SECRET): array {
		$r = array_merge([
			'method' => 'GET', 'path' => '/apps/buscador_documental/worker/v1/files/5/content', 'query' => '',
			'range' => 'bytes=0-9', 'ifMatch' => 'e1', 'body' => '', 'timestamp' => (string)self::NOW,
			'nonce' => 'nonce-0123456789ab', 'jobId' => '3', 'epoch' => '2',
		], $over);
		$mac = WorkerAuth::sign($secret, WorkerAuth::signedString(
			$r['method'], $r['path'], $r['query'], $r['range'], $r['ifMatch'], 'w1', $r['body'], (int)$r['timestamp'], $r['nonce'], $r['jobId'], $r['epoch'],
		));
		$r['authorization'] = $over['authorization'] ?? "Bearer w1.$mac";
		return $r;
	}

	private function reason(callable $f): string {
		try {
			$f();
		} catch (WorkerAuthException $e) {
			return $e->reason;
		}
		$this->fail('expected WorkerAuthException');
	}

	public function testValidRequestReturnsWorkerId(): void {
		$this->assertSame('w1', $this->auth()->authenticate($this->request()));
	}

	public function testNonceTtlIsAtLeastTwiceTheWindow(): void {
		$this->assertGreaterThanOrEqual(2 * WorkerAuth::WINDOW_SECONDS, WorkerAuth::NONCE_TTL_SECONDS);
	}

	public function testBadMacIsRejected(): void {
		$r = $this->request();
		$r['authorization'] = 'Bearer w1.' . str_repeat('0', 64);
		$this->assertSame('bad_mac', $this->reason(fn () => $this->auth()->authenticate($r)));
	}

	public function testEverySignedFieldIsCovered(): void {
		foreach (['path' => '/other', 'query' => 'x=1', 'range' => 'bytes=0-99', 'ifMatch' => 'e2', 'jobId' => '4', 'epoch' => '3', 'method' => 'POST', 'body' => 'x'] as $field => $value) {
			$r = $this->request();
			$r[$field] = $value;           // tamper after signing
			$this->assertSame('bad_mac', $this->reason(fn () => $this->auth()->authenticate($r)), $field);
		}
	}

	public function testWorkerIdIsCovered(): void {
		$r = $this->request();
		$r['authorization'] = str_replace('Bearer w1.', 'Bearer w2.', $r['authorization']);
		$this->assertSame('bad_mac', $this->reason(fn () => $this->auth()->authenticate($r)));
	}

	public function testTimestampWindowBothDirections(): void {
		foreach ([self::NOW - 121, self::NOW + 121] as $ts) {
			$this->assertSame('timestamp_out_of_window', $this->reason(fn () => $this->auth()->authenticate($this->request(['timestamp' => (string)$ts]))));
		}
		$this->assertSame('w1', $this->auth()->authenticate($this->request(['timestamp' => (string)(self::NOW - 120)])));
	}

	public function testReplayIsRejected(): void {
		$a = $this->auth();
		$r = $this->request();
		$a->authenticate($r);
		$this->assertSame('nonce_replayed', $this->reason(fn () => $a->authenticate($r)));
	}

	public function testNonceIsNotStoredWhenTheMacFails(): void {
		$a = $this->auth();
		$bad = $this->request();
		$bad['authorization'] = 'Bearer w1.' . str_repeat('1', 64);
		$this->reason(fn () => $a->authenticate($bad));
		$this->assertSame([], $this->seen, 'unauthenticated callers must not fill the nonce cache');
	}

	public function testMissingOrShortSecretFailsClosed(): void {
		$this->assertSame('secret_not_configured', $this->reason(fn () => $this->auth('')->authenticate($this->request())));
		$this->assertSame('secret_not_configured', $this->reason(fn () => $this->auth('short')->authenticate($this->request())));
	}

	public function testNoCacheFailsClosed(): void {
		$this->assertSame('no_cache_available', $this->reason(fn () => $this->auth(self::SECRET, false)->authenticate($this->request())));
		$this->assertSame('cache_has_no_atomic_add', $this->reason(fn () => $this->auth(self::SECRET, true, false)->authenticate($this->request())));
	}

	public function testMalformedHeaders(): void {
		$this->assertSame('malformed_authorization', $this->reason(fn () => $this->auth()->authenticate($this->request(['authorization' => 'Basic abc']))));
		$this->assertSame('malformed_headers', $this->reason(fn () => $this->auth()->authenticate($this->request(['nonce' => 'short']))));
		$this->assertSame('malformed_headers', $this->reason(fn () => $this->auth()->authenticate($this->request(['timestamp' => 'abc']))));
	}
}
