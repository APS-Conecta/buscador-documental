<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Controller;

use OCA\BuscadorDocumental\Controller\SearchController;
use OCA\BuscadorDocumental\Search\Searcher;
use OCA\BuscadorDocumental\Search\SearchRequest;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class SearchControllerTest extends TestCase {
	private function controller(array $params, array $result, ?string $uid = 'bea', ?SearchRequest &$seen = null): SearchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$searcher = $this->createMock(Searcher::class);
		$searcher->method('search')->willReturnCallback(function (string $u, SearchRequest $r) use ($result, &$seen) {
			$seen = $r;
			return $result + ['uid' => $u];
		});
		$searcher->method('modes')->willReturn([['mode' => 'exacta', 'enabled' => true, 'reason' => null]]);
		$session = $this->createMock(IUserSession::class);
		$user = $uid === null ? null : $this->createMock(IUser::class);
		$user?->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return new SearchController($request, $searcher, $session);
	}

	public function testItSearchesAsTheSessionUserAndNeverCaches(): void {
		$r = $this->controller(['query' => 'glucosa', 'mode' => 'mixta', '_route' => 'x', 'junk' => 1], ['status' => 'ok'])->search();
		$this->assertSame(200, $r->getStatus());
		$this->assertSame('bea', $r->getData()['uid']);
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($r);   // getHeaders() needs a running server
		$this->assertSame('no-store', $headers['Cache-Control']);
	}

	public function testOnlyTheDocumentedParametersReachTheRequest(): void {
		$seen = null;
		$this->controller(['query' => 'x', 'uid' => 'mallory', 'principals' => ['u:mallory'], 'page_size' => 50], ['status' => 'ok'], 'bea', $seen)->search();
		$this->assertSame(50, $seen->pageSize);
		$this->assertSame('x', $seen->query);
	}

	public function testBadInputIsA422WithASpanishMessage(): void {
		$r = $this->controller(['query' => 'x', 'page_size' => 7], ['status' => 'ok'])->search();
		$this->assertSame(422, $r->getStatus());
		$this->assertSame('invalid', $r->getData()['status']);
		$this->assertStringContainsString('tamaño', $r->getData()['message']);
		$this->assertSame([], $r->getData()['hits']);
	}

	/** @dataProvider statuses */
	public function testStatusCodes(string $status, int $http): void {
		$this->assertSame($http, $this->controller(['query' => 'x'], ['status' => $status])->search()->getStatus());
	}

	public static function statuses(): array {
		return [['ok', 200], ['too_many', 200], ['cursor_expired', 410], ['invalid_cursor', 400], ['unavailable', 503]];
	}

	public function testNoSessionUserIs401(): void {
		$r = $this->controller(['query' => 'x'], ['status' => 'ok'], null)->search();
		$this->assertSame(401, $r->getStatus());
		$this->assertSame(401, $this->controller([], [], null)->modes()->getStatus());
	}

	public function testModes(): void {
		$r = $this->controller([], [])->modes();
		$this->assertSame(200, $r->getStatus());
		$this->assertSame(['modes' => [['mode' => 'exacta', 'enabled' => true, 'reason' => null]]], $r->getData());
	}
}
