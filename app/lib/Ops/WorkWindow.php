<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ops;

use DateTimeImmutable;
use DateTimeZone;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Reconcile\ReconcileWindow;
use OCA\BuscadorDocumental\Scope\Settings;
use Throwable;

/** The night window for heavy work (ADR-0015). `claim_job` enforces it; this validates and stores it. */
class WorkWindow {
	public function __construct(private Settings $settings, private AuditLog $audit, private IndexDb $db) {
	}

	/** @return array{window: ?string, lanes: string, tz: string, open_now: bool} */
	public function show(DateTimeImmutable $now): array {
		$window = $this->settings->get('work_window');
		$tz = $this->settings->get('work_tz') ?: 'UTC';
		$open = true;
		if ($window !== null && $window !== '') {
			try {
				$open = ReconcileWindow::contains($window, $now->setTimezone(new DateTimeZone($tz)));
			} catch (Throwable) {
				$open = true;                                    // what claim_job does with an unusable setting
			}
		}
		return ['window' => $window === '' ? null : $window, 'lanes' => $this->settings->get('work_window_lanes') ?? 'vec', 'tz' => $tz, 'open_now' => $open];
	}

	/** Seconds until the window next opens; null when there is no window or it is open now. */
	public function opensInSeconds(DateTimeImmutable $now): ?int {
		$s = $this->show($now);
		if ($s['window'] === null || $s['open_now']) {
			return null;
		}
		$local = $now->setTimezone(new DateTimeZone($s['tz']));
		$start = $local->setTime((int)substr($s['window'], 0, 2), (int)substr($s['window'], 3, 2));
		if ($start <= $local) {
			$start = $start->modify('+1 day');
		}
		return $start->getTimestamp() - $local->getTimestamp();
	}

	/** @throws OpsException */
	public function set(string $window, string $lanes, string $tz): void {
		if (preg_match('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/D', $window) !== 1) {
			throw new OpsException('The window must look like HH:MM-HH:MM with two digits for hours (00-23) and minutes, for example 01:00-05:00.');
		}
		if (substr($window, 0, 5) === substr($window, 6, 5)) {
			throw new OpsException('The two ends of the window must differ. To remove the window use `window off`.');
		}
		if (!in_array($lanes, ['vec', 'all'], true)) {
			throw new OpsException('The lanes must be vec or all.');
		}
		// PHP accepts offsets and abbreviations that PostgreSQL reads differently (+03:00 is UTC+3 to one and UTC-3 to the other),
		// and the window is enforced by the database: only names both know are allowed.
		try {
			new DateTimeZone($tz);
			$known = $this->db->rows('SELECT 1 FROM pg_timezone_names WHERE name = ?', [$tz]) !== [];
		} catch (Throwable) {
			$known = false;
		}
		if (!$known) {
			throw new OpsException('Unknown time zone. Use an IANA name such as America/Santiago that the database also knows.');
		}
		$this->settings->set('work_window', $window);
		$this->settings->set('work_window_lanes', $lanes);
		$this->settings->set('work_tz', $tz);
		$this->audit->record('occ', 'window.set', null, ['window' => $window, 'lanes' => $lanes]);
	}

	public function off(): void {
		$this->settings->delete('work_window');
		$this->settings->delete('work_window_lanes');
		$this->settings->delete('work_tz');
		$this->audit->record('occ', 'window.set', null, ['window' => 'off']);
	}
}
