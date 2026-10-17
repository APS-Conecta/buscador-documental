<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\Cursor;
use PHPUnit\Framework\TestCase;

class CursorTest extends TestCase {
	private const SECRET = 'instance-secret-0123456789';
	private const QID = '11111111-2222-4333-8444-555555555555';

	public function testARoundTripKeepsPositionAndSnapshot(): void {
		$c = new Cursor(self::SECRET);
		$token = $c->make(self::QID, 75, 'uidhash-a');
		$this->assertSame(['qid' => self::QID, 'pos' => 75], $c->read($token, 'uidhash-a'));
	}

	public function testTheTokenIsUrlSafeAndHoldsNoUserNameOrQuery(): void {
		$token = (new Cursor(self::SECRET))->make(self::QID, 5, 'alice');
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token);
		$this->assertStringNotContainsString('alice', base64_decode(strtr(explode('.', $token)[0], '-_', '+/')));
	}

	public function testAnotherUsersCursorIsRefused(): void {
		$c = new Cursor(self::SECRET);
		$this->assertNull($c->read($c->make(self::QID, 5, 'uidhash-a'), 'uidhash-b'));
	}

	public function testATamperedOrForeignTokenIsRefused(): void {
		$c = new Cursor(self::SECRET);
		$token = $c->make(self::QID, 5, 'u');
		[$body, $mac] = explode('.', $token);
		$forged = rtrim(strtr(base64_encode(json_encode(['q' => self::QID, 'p' => 9999, 'u' => hash('sha256', 'u')])), '+/', '-_'), '=');
		foreach ([$forged . '.' . $mac, $body . '.' . strrev($mac), $body, '', 'a.b.c', "$body.", '.', str_repeat('x', 600)] as $bad) {
			$this->assertNull($c->read($bad, 'u'), $bad);
		}
		$this->assertNull((new Cursor('another-secret'))->read($token, 'u'));
	}

	public function testNegativeOrNonIntegerPositionsAreRefused(): void {
		$c = new Cursor(self::SECRET);
		foreach ([-1, 'x', 1.5, null] as $pos) {
			$body = rtrim(strtr(base64_encode(json_encode(['q' => self::QID, 'p' => $pos, 'u' => hash('sha256', 'u')])), '+/', '-_'), '=');
			$mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, self::SECRET, true)), '+/', '-_'), '=');
			$this->assertNull($c->read("$body.$mac", 'u'), json_encode($pos));
		}
	}

	public function testAnEmptySecretIsNotAccepted(): void {
		$this->expectException(\InvalidArgumentException::class);
		new Cursor('');
	}

	public function testUidHashIsStableAndOneWay(): void {
		$h = Cursor::uidHash('alice');
		$this->assertSame($h, Cursor::uidHash('alice'));
		$this->assertNotSame($h, Cursor::uidHash('bob'));
		$this->assertStringNotContainsString('alice', $h);
	}
}
