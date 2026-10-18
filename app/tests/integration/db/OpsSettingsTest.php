<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use DateTimeImmutable;
use DateTimeZone;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Ops\OpsException;
use OCA\BuscadorDocumental\Ops\Throttle;
use OCA\BuscadorDocumental\Ops\WorkWindow;
use OCA\BuscadorDocumental\Scope\Settings;

class OpsSettingsTest extends DbTestCase {
	private function db(): IndexDb {
		return new IndexDb($this->connection($this->app()));
	}

	private function window(): WorkWindow {
		$db = $this->db();
		return new WorkWindow(new Settings($db), new AuditLog($db, new Pseudonyms($db)));
	}

	private function throttle(): Throttle {
		$db = $this->db();
		return new Throttle(new Settings($db), new AuditLog($db, new Pseudonyms($db)));
	}

	public function testByDefaultThereIsNoWindow(): void {
		$s = $this->window()->show(new DateTimeImmutable('2026-10-18 12:00', new DateTimeZone('UTC')));
		$this->assertSame(['window' => null, 'lanes' => 'vec', 'tz' => 'UTC', 'open_now' => true], $s);
	}

	public function testSettingAWindowStoresItWithTheZoneAndReportsWhetherItIsOpen(): void {
		$w = $this->window();
		$w->set('01:00-05:00', 'vec', 'America/Santiago');
		$noon = new DateTimeImmutable('2026-10-18 15:00', new DateTimeZone('UTC'));          // 12:00 in Santiago (UTC-3 in October)
		$night = new DateTimeImmutable('2026-10-18 05:00', new DateTimeZone('UTC'));         // 02:00 in Santiago
		$this->assertSame(['window' => '01:00-05:00', 'lanes' => 'vec', 'tz' => 'America/Santiago', 'open_now' => false], $w->show($noon));
		$this->assertTrue($w->show($night)['open_now']);
		$this->assertSame('01:00-05:00', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'work_window'")->fetchColumn());
		$this->assertSame('America/Santiago', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'work_tz'")->fetchColumn());
		$this->assertSame('window.set', $this->app()->query('SELECT action FROM aps_search.audit_log ORDER BY id DESC LIMIT 1')->fetchColumn());
	}

	public function testOffRemovesTheWindow(): void {
		$w = $this->window();
		$w->set('22:00-06:00', 'all', 'UTC');
		$w->off();
		$this->assertNull($w->show(new DateTimeImmutable('now', new DateTimeZone('UTC')))['window']);
		$this->assertSame(0, (int)$this->app()->query("SELECT count(*) FROM aps_search.settings WHERE key LIKE 'work_%'")->fetchColumn());
	}

	/** @dataProvider badWindows */
	public function testNonsenseIsRefused(string $window, string $lanes, string $tz, string $needle): void {
		try {
			$this->window()->set($window, $lanes, $tz);
			$this->fail("accepted $window $lanes $tz");
		} catch (OpsException $e) {
			$this->assertStringContainsString($needle, $e->getMessage());
		}
		$this->assertSame(0, (int)$this->app()->query("SELECT count(*) FROM aps_search.settings WHERE key LIKE 'work_%'")->fetchColumn(), 'nothing stored');
	}

	public static function badWindows(): array {
		return [
			'format' => ['tonight', 'vec', 'UTC', 'HH:MM-HH:MM'],
			'hour' => ['25:00-26:00', 'vec', 'UTC', 'HH:MM-HH:MM'],
			'one digit' => ['1:00-5:00', 'vec', 'UTC', 'HH:MM-HH:MM'],
			'same ends' => ['01:00-01:00', 'vec', 'UTC', 'differ'],
			'lanes' => ['01:00-05:00', 'text', 'UTC', 'vec or all'],
			'zone' => ['01:00-05:00', 'vec', 'Mars/Olympus', 'time zone'],
		];
	}

	public function testThrottleDefaultsSetAndRefuse(): void {
		$t = $this->throttle();
		$this->assertSame(['text' => 4, 'vec' => 2], $t->show());
		$t->set(2, null);
		$t->set(null, 1);
		$this->assertSame(['text' => 2, 'vec' => 1], $t->show());
		foreach ([0, 17, -1] as $bad) {
			try {
				$t->set($bad, null);
				$this->fail("accepted $bad");
			} catch (OpsException $e) {
				$this->assertStringContainsString('1 to 16', $e->getMessage());
			}
		}
		$this->assertSame(['text' => 2, 'vec' => 1], $t->show());
		$t->reset();
		$this->assertSame(['text' => 4, 'vec' => 2], $t->show());
		$this->assertSame('throttle.set', $this->app()->query('SELECT action FROM aps_search.audit_log ORDER BY id DESC LIMIT 1')->fetchColumn());
	}
}
