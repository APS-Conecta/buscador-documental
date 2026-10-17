<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\contract;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Controller\WorkerController;
use OCA\BuscadorDocumental\Security\WorkerAuth;
use OCA\BuscadorDocumental\Worker\ClaimGuard;
use OCA\BuscadorDocumental\Worker\NodeLocator;
use OCA\BuscadorDocumental\Worker\RangeStreamResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Server side of the contract: every x-example in openapi/worker-v1.yaml, with its golden signature, goes through the real
 * WorkerAuth and the real WorkerController (collaborators faked) and must produce the example's response.
 * The Python client is tested against the same file (worker/tests/test_contract.py).
 */
class ContractTest extends TestCase {
	private static function spec(): array {
		return Yaml::parse((string)file_get_contents(__DIR__ . '/../../openapi/worker-v1.yaml'));
	}

	/** @return iterable<string, array{0:array,1:array,2:string}> */
	public static function examples(): iterable {
		$spec = self::spec();
		foreach ($spec['paths'] as $path => $ops) {
			foreach ($ops['get']['x-examples'] as $ex) {
				yield $ex['name'] => [$ex['request'], $ex['response'], $spec['x-example-secret']];
			}
		}
	}

	#[DataProvider('examples')]
	public function testExampleSignatureAndResponse(array $req, array $res, string $secret): void {
		$signed = WorkerAuth::signedString($req['method'], $req['path'], $req['query'], $req['range'], $req['if_match'], $req['worker_id'], '', $req['timestamp'], $req['nonce'], (string)$req['job_id'], (string)$req['lease_epoch']);
		$this->assertSame($req['signature'], WorkerAuth::sign($secret, $signed), 'golden signature drifted');

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($secret);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($req['timestamp']);
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturn(true);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		$headers = [
			'Authorization' => 'Bearer ' . $req['worker_id'] . '.' . $req['signature'], 'X-BD-Timestamp' => (string)$req['timestamp'],
			'X-BD-Nonce' => $req['nonce'], 'X-BD-Job-Id' => (string)$req['job_id'], 'X-BD-Lease-Epoch' => (string)$req['lease_epoch'],
			'Range' => $req['range'], 'If-Match' => $req['if_match'],
		];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $h) => $headers[$h] ?? '');
		$request->method('getMethod')->willReturn($req['method']);
		$request->method('getRawPathInfo')->willReturn($req['path']);
		$request->method('getRequestUri')->willReturn($req['path'] . ($req['query'] === '' ? '' : '?' . $req['query']));

		$claims = $this->createMock(ClaimGuard::class);
		$claims->method('overCap')->willReturn(false);
		$claims->method('activeClaim')->willReturn(['etag' => 'etag-abc']);
		$exclusion = $this->createMock(ExclusionChecker::class);
		$exclusion->method('isExcluded')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getEtag')->willReturn('etag-abc');
		$file->method('getSize')->willReturn(2048);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getName')->willReturn('scan.pdf');
		$file->method('getMTime')->willReturn(1759999000);
		$file->method('isEncrypted')->willReturn(false);
		$file->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'r+'));
		$nodes = $this->createMock(NodeLocator::class);
		$nodes->method('locate')->willReturn($file);

		$c = new WorkerController($request, new WorkerAuth($config, $factory, $time), $claims, $nodes, $exclusion, $this->createMock(LoggerInterface::class));
		$path = $req['path'];
		$response = str_ends_with($path, '/meta') ? $c->meta(42) : $c->content(42);

		$this->assertSame($res['status'], $response->getStatus());
		if (isset($res['json'])) {
			$this->assertSame($res['json'], $response->getData());
		}
		foreach ($res['headers'] ?? [] as $k => $v) {
			$this->assertInstanceOf(RangeStreamResponse::class, $response);
			$this->assertSame($v, $response->ownHeaders()[$k] ?? null);
		}
	}
}
