<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Controller;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Controller\WorkerController;
use OCA\BuscadorDocumental\Security\WorkerAuth;
use OCA\BuscadorDocumental\Security\WorkerAuthException;
use OCA\BuscadorDocumental\Worker\ClaimGuard;
use OCA\BuscadorDocumental\Worker\NodeLocator;
use OCA\BuscadorDocumental\Worker\RangeStreamResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkerControllerTest extends TestCase {
	private WorkerAuth&MockObject $auth;
	private ClaimGuard&MockObject $claims;
	private NodeLocator&MockObject $nodes;
	private ExclusionChecker&MockObject $exclusion;
	private LoggerInterface&MockObject $logger;

	private function controller(array $headers = [], ?bool $excluded = false, ?ClaimGuard $claims = null, ?\Throwable $exclusionFails = null, ?WorkerAuth $auth = null): WorkerController {
		$headers += ['Authorization' => 'Bearer w1.x', 'X-BD-Job-Id' => '7', 'X-BD-Lease-Epoch' => '3', 'If-Match' => 'etag-abc', 'Range' => ''];
		$req = $this->createMock(IRequest::class);
		$req->method('getHeader')->willReturnCallback(static fn (string $h) => $headers[$h] ?? '');
		$req->method('getMethod')->willReturn('GET');
		$req->method('getRawPathInfo')->willReturn('/apps/buscador_documental/worker/v1/files/42/content');
		$req->method('getRequestUri')->willReturn('/index.php/apps/buscador_documental/worker/v1/files/42/content');
		if ($auth === null) {
			$auth = $this->createMock(WorkerAuth::class);
			$auth->method('authenticate')->willReturn('w1');
		}
		$this->auth = $auth;
		if ($claims === null) {
			$claims = $this->createMock(ClaimGuard::class);
			$claims->method('activeClaim')->willReturn(['etag' => 'etag-abc']);
			$claims->method('overCap')->willReturn(false);
		}
		$this->claims = $claims;
		$this->nodes = $this->createMock(NodeLocator::class);
		$this->exclusion = $this->createMock(ExclusionChecker::class);
		if ($exclusionFails !== null) {
			$this->exclusion->method('isExcluded')->willThrowException($exclusionFails);
		} else {
			$this->exclusion->method('isExcluded')->willReturn($excluded);
		}
		$this->logger = $this->createMock(LoggerInterface::class);
		return new WorkerController($req, $this->auth, $this->claims, $this->nodes, $this->exclusion, $this->logger);
	}

	private function file(string $bytes = '0123456789'): File {
		$f = $this->createMock(File::class);
		$f->method('getEtag')->willReturn('etag-abc');
		$f->method('getSize')->willReturn(strlen($bytes));
		$f->method('getMimeType')->willReturn('application/pdf');
		$f->method('getName')->willReturn('scan.pdf');
		$f->method('getMTime')->willReturn(1759999000);
		$f->method('isEncrypted')->willReturn(false);
		$f->method('fopen')->willReturnCallback(static function () use ($bytes) {
			$s = fopen('php://memory', 'r+');
			fwrite($s, $bytes);
			rewind($s);
			return $s;
		});
		return $f;
	}

	private function body(RangeStreamResponse $r): string {
		ob_start();
		$r->callback($this->createMock(\OCP\AppFramework\Http\IOutput::class));
		return (string)ob_get_clean();
	}

	public function testMetaHappyPathReturnsOnlyTheContractFields(): void {
		$c = $this->controller();
		$this->nodes->method('locate')->willReturn($this->file());
		$r = $c->meta(42);
		$this->assertSame(200, $r->getStatus());
		$this->assertSame(['file_id', 'etag', 'size', 'mime', 'name', 'mtime', 'encrypted'], array_keys($r->getData()));
	}

	public function testBadAuthIs401WithEmptyBodyAndOnlyACodeInTheLog(): void {
		$auth = $this->createMock(WorkerAuth::class);
		$auth->method('authenticate')->willThrowException(new WorkerAuthException('bad_mac'));
		$c = $this->controller([], false, null, null, $auth);
		$this->logger->expects($this->once())->method('warning')->with($this->anything(), $this->callback(
			static fn (array $ctx) => $ctx['reason'] === 'auth:bad_mac' && count($ctx) === 3,
		));
		$r = $c->meta(42);
		$this->assertSame(401, $r->getStatus());
		$this->assertSame([], $r->getData());
	}

	public function testNoActiveClaimIs403AndNoBytesAreTouched(): void {
		$claims = $this->createMock(ClaimGuard::class);
		$claims->method('activeClaim')->willReturn(null);
		$claims->method('overCap')->willReturn(false);
		$c = $this->controller([], false, $claims);
		$this->nodes->expects($this->never())->method('locate');
		$this->assertSame(403, $c->content(42)->getStatus());
	}

	public function testMalformedJobHeadersAre403(): void {
		$this->assertSame(403, $this->controller(['X-BD-Job-Id' => 'abc'])->meta(42)->getStatus());
	}

	public function testOverCapIs429(): void {
		$claims = $this->createMock(ClaimGuard::class);
		$claims->method('overCap')->willReturn(true);
		$this->assertSame(429, $this->controller([], false, $claims)->meta(42)->getStatus());
	}

	public function testExcludedFileIsRefusedBeforeServingBytes(): void {
		$c = $this->controller([], true);
		$this->nodes->expects($this->never())->method('locate');
		$this->assertSame(403, $c->content(42)->getStatus());
	}

	public function testExclusionUnavailableFailsClosedWith503(): void {
		$c = $this->controller([], false, null, new ExclusionUnavailable('down'));
		$this->assertSame(503, $c->content(42)->getStatus());
	}

	public function testDeletedFileIs404NotARetryable503(): void {
		$c = $this->controller([], false, null, new \OCA\BuscadorDocumental\Acl\FileGone('gone'));
		$this->assertSame(404, $c->content(42)->getStatus());
	}

	public function testContentRequiresIfMatch(): void {
		$c = $this->controller(['If-Match' => '']);
		$this->nodes->method('locate')->willReturn($this->file());
		$this->assertSame(428, $c->content(42)->getStatus());
	}

	public function testContentWithStaleEtagIs412(): void {
		$c = $this->controller(['If-Match' => 'old']);
		$this->nodes->method('locate')->willReturn($this->file());
		$this->assertSame(412, $c->content(42)->getStatus());
	}

	public function testContentWholeAndRange(): void {
		$c = $this->controller();
		$this->nodes->method('locate')->willReturn($this->file());
		$whole = $c->content(42);
		$this->assertSame(200, $whole->getStatus());
		$this->assertSame('0123456789', $this->body($whole));

		$c2 = $this->controller(['Range' => 'bytes=2-5']);
		$this->nodes->method('locate')->willReturn($this->file());
		$part = $c2->content(42);
		$this->assertSame(206, $part->getStatus());
		$this->assertSame('2345', $this->body($part));
		$this->assertSame('bytes 2-5/10', $part->ownHeaders()['Content-Range']);
	}

	public function testUnsatisfiableRangeIs416(): void {
		$c = $this->controller(['Range' => 'bytes=50-60']);
		$this->nodes->method('locate')->willReturn($this->file());
		$this->assertSame(416, $c->content(42)->getStatus());
	}

	public function testParseRange(): void {
		$this->assertSame([0, 9], WorkerController::parseRange('bytes=0-', 10));
		$this->assertSame([7, 9], WorkerController::parseRange('bytes=-3', 10));
		$this->assertSame([2, 9], WorkerController::parseRange('bytes=2-99', 10));
		$this->assertNull(WorkerController::parseRange('bytes=5-2', 10));
		$this->assertNull(WorkerController::parseRange('bytes=0-1,4-5', 10));
		$this->assertNull(WorkerController::parseRange('items=0-1', 10));
		$this->assertNull(WorkerController::parseRange('bytes=0-1', 0));
	}
}
