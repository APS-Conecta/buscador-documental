<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\EmbedClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmbedClientTest extends TestCase {
	private const PROFILE = ['gen' => 1, 'model' => 'bge-m3:567m', 'digest' => 'sha256:abc', 'dim' => 3, 'query_prefix' => 'query: ', 'max_tokens' => 8192];
	/** @var list<array{0: string, 1: string, 2: array}> */
	private array $calls = [];
	/** @var list<string> */
	private array $logged = [];

	private function response(int $status, array $body): IResponse {
		$r = $this->createMock(IResponse::class);
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn(json_encode($body));
		return $r;
	}

	/** @param array<string, mixed> $config @param callable(string $method, string $uri, array $o): IResponse $answer */
	private function client(callable $answer, array $config = ['embed_url' => 'http://ollama:11434', 'embed_token' => ''], ?LoggerInterface $log = null): EmbedClient {
		$http = $this->createMock(IClient::class);
		foreach (['get', 'post'] as $m) {
			$http->method($m)->willReturnCallback(function (string $uri, array $o = []) use ($m, $answer) {
				$this->calls[] = [$m, $uri, $o];
				return $answer($m, $uri, $o);
			});
		}
		$svc = $this->createMock(IClientService::class);
		$svc->method('newClient')->willReturn($http);
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = '', bool $lazy = false): string => (string)($config[$key] ?? $default));
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function ($k) use (&$store) { return $store[$k] ?? null; });
		$cache->method('set')->willReturnCallback(static function ($k, $v) use (&$store) { $store[$k] = $v; return true; });
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);
		$log ??= $this->createMock(LoggerInterface::class);
		return new EmbedClient($svc, $cfg, $factory, $log);
	}

	private function ok(array $vector = [3.0, 0.0, 4.0], string $digest = 'sha256:abc'): callable {
		return function (string $method, string $uri) use ($vector, $digest): IResponse {
			if ($method === 'get') {
				return $this->response(200, ['models' => [['name' => 'bge-m3:567m', 'digest' => $digest]]]);
			}
			return $this->response(200, ['embeddings' => [$vector]]);
		};
	}

	public function testReturnsTheUnitQueryVectorAndSendsTheDocumentedRequest(): void {
		$v = $this->client($this->ok())->embedQuery('hemoglobina', self::PROFILE, 1500);
		$this->assertEqualsWithDelta([0.6, 0.0, 0.8], $v, 1e-9);
		[, $uri, $o] = array_values(array_filter($this->calls, static fn ($c) => $c[0] === 'post'))[0];
		$this->assertSame('http://ollama:11434/api/embed', $uri);
		$this->assertSame(['model' => 'bge-m3:567m', 'input' => ['query: hemoglobina'], 'truncate' => false, 'keep_alive' => -1, 'options' => ['num_ctx' => 8192]], json_decode($o['body'], true));
		$this->assertTrue($o['nextcloud']['allow_local_address']);
		$this->assertFalse($o['allow_redirects']);
		$this->assertEqualsWithDelta(1.5, $o['timeout'], 1e-9);
		$this->assertSame('application/json', $o['headers']['Content-Type']);
	}

	public function testTheTokenIsSentAsABearerHeaderOnlyWhenConfigured(): void {
		$this->client($this->ok(), ['embed_url' => 'https://e.example', 'embed_token' => 'tok-123'])->embedQuery('x', self::PROFILE, 1000);
		foreach ($this->calls as [, , $o]) {
			$this->assertSame('Bearer tok-123', $o['headers']['Authorization']);
		}
		$this->calls = [];
		$this->client($this->ok())->embedQuery('x', self::PROFILE, 1000);
		foreach ($this->calls as [, , $o]) {
			$this->assertArrayNotHasKey('Authorization', $o['headers']);
		}
	}

	/** @dataProvider badUrls */
	public function testAnUnusableEndpointSettingNeverMakesARequest(string $url): void {
		$this->assertNull($this->client($this->ok(), ['embed_url' => $url])->embedQuery('x', self::PROFILE, 1000));
		$this->assertSame([], $this->calls);
	}

	public static function badUrls(): array {
		return [[''], ['not a url'], ['file:///etc/passwd'], ['ftp://x/y'], ['gopher://x'], ['http://user:pw@host:1/'], ['http:///nohost'], ['javascript:alert(1)']];
	}

	public function testEveryFailureIsNullAndTheLogHoldsNoUrlTokenOrQuery(): void {
		$cases = [
			'refused' => static function (): IResponse { throw new \RuntimeException('connect to http://ollama:11434 failed for SECRETQUERY tok-123'); },
			'status' => fn (): IResponse => $this->response(500, []),
			'html' => function (): IResponse {
				$r = $this->createMock(IResponse::class);
				$r->method('getStatusCode')->willReturn(200);
				$r->method('getBody')->willReturn('<html>');
				return $r;
			},
			'shape' => fn (string $m): IResponse => $m === 'get' ? $this->response(200, ['models' => [['name' => 'bge-m3:567m', 'digest' => 'sha256:abc']]]) : $this->response(200, ['embeddings' => 'no']),
			'dim' => $this->ok([1.0, 0.0]),
			'nan' => $this->ok([1.0, NAN, 0.0]),
			'zero' => $this->ok([0.0, 0.0, 0.0]),
			'string' => $this->ok(['a', 'b', 'c']),
		];
		foreach ($cases as $name => $answer) {
			$log = $this->createMock(LoggerInterface::class);
			$messages = [];
			foreach (['warning', 'error', 'info', 'debug'] as $level) {
				$log->method($level)->willReturnCallback(function (string $m, array $ctx = []) use (&$messages): void { $messages[] = $m . json_encode($ctx); });
			}
			$this->assertNull($this->client($answer, ['embed_url' => 'http://ollama:11434', 'embed_token' => 'tok-123'], $log)->embedQuery('SECRETQUERY', self::PROFILE, 1000), $name);
			$text = implode("\n", $messages);
			foreach (['SECRETQUERY', 'tok-123', 'ollama:11434', 'bge-m3'] as $leak) {
				$this->assertStringNotContainsString($leak, $text, "$name leaks $leak");
			}
		}
	}

	public function testAChangedModelDigestIsRefusedAndTheCheckIsCached(): void {
		$c = $this->client($this->ok([1.0, 0.0, 0.0], 'sha256:other'));
		$this->assertNull($c->embedQuery('x', self::PROFILE, 1000));
		$this->calls = [];
		$good = $this->client($this->ok());
		$this->assertNotNull($good->embedQuery('x', self::PROFILE, 1000));
		$this->assertNotNull($good->embedQuery('y', self::PROFILE, 1000));
		$gets = array_filter($this->calls, static fn ($c) => $c[0] === 'get');
		$this->assertCount(1, $gets, 'one /api/tags call serves both queries');
	}

	public function testInspectReturnsTheDigestAndTheRealDimension(): void {
		$c = $this->client($this->ok([0.0, 3.0, 4.0]));
		$this->assertSame(['digest' => 'sha256:abc', 'dim' => 3], $c->inspect('bge-m3:567m', 8192, 'prueba', 3000));
	}

	/** @dataProvider probeFailures */
	public function testInspectNamesWhatWentWrongWithoutLeakingAnything(callable $answer, array $config, string $reason): void {
		$c = $this->client($answer, $config);
		try {
			$c->inspect('bge-m3:567m', 8192, 'prueba', 3000);
			$this->fail('should have failed');
		} catch (\OCA\BuscadorDocumental\Search\ProbeFailed $e) {
			$this->assertSame($reason, $e->reason);
			$this->assertStringNotContainsString('ollama', $e->getMessage());
		}
	}

	public static function probeFailures(): array {
		return [
			'not configured' => [static fn () => throw new \LogicException('never called'), ['embed_url' => ''], 'not_configured'],
		];
	}

	public function testInspectReasonsForUnreachableMissingModelAndBadAnswer(): void {
		$down = $this->client(static function (): IResponse { throw new \RuntimeException('down http://ollama:11434'); });
		$this->assertSame('unreachable', $this->reasonOf($down));
		$missing = $this->client(fn (string $m): IResponse => $this->response(200, ['models' => [['name' => 'otro:1b', 'digest' => 'x']]]));
		$this->assertSame('model_not_found', $this->reasonOf($missing));
		$bad = $this->client(fn (string $m): IResponse => $m === 'get' ? $this->response(200, ['models' => [['name' => 'bge-m3:567m', 'digest' => 'sha256:abc']]]) : $this->response(200, ['embeddings' => 'x']));
		$this->assertSame('bad_answer', $this->reasonOf($bad));
	}

	private function reasonOf(EmbedClient $c): string {
		try {
			$c->inspect('bge-m3:567m', 8192, 'prueba', 3000);
		} catch (\OCA\BuscadorDocumental\Search\ProbeFailed $e) {
			return $e->reason;
		}
		return 'no failure';
	}
}
