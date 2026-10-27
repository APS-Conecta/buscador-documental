<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Security;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Ops\OpsException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/** `occ buscador:rotate-secret` (ADR-0015): a new worker secret now, the old one still valid for a grace period so the worker can be updated. */
class SecretRotation {
	private const APP = WorkerAuth::APP_ID;
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	private const MIN_SECRET = 32;  // characters; also what `occ buscador:doctor` and the suite's generator rely on

	public function __construct(private IAppConfig $config, private ITimeFactory $time, private AuditLog $audit) {
	}

	/** A previous secret is still accepted right now (a second rotation would cut it off). */
	public function previousStillValid(): bool {
		$until = $this->config->getValueString(self::APP, 'worker_secret_prev_until', '');
		return strlen($this->config->getValueString(self::APP, 'worker_secret_prev', '')) >= self::MIN_SECRET
			&& preg_match('/^[0-9]{1,12}$/D', $until) === 1 && (int)$until > $this->time->getTime();
	}

	/** @return string the new secret: shown to the administrator once, never logged or audited */
	public function rotate(int $graceHours): string {
		if ($graceHours < 0 || $graceHours > 720) {
			throw new OpsException('The grace period is a whole number of hours from 0 to 720.');
		}
		$old = $this->config->getValueString(self::APP, 'worker_secret', '');
		$new = '';
		for ($i = 0; $i < 48; $i++) {
			$new .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
		}
		if ($graceHours > 0 && strlen($old) >= self::MIN_SECRET) {
			$this->config->setValueString(self::APP, 'worker_secret_prev', $old, false, true);
			$this->config->setValueString(self::APP, 'worker_secret_prev_until', (string)($this->time->getTime() + $graceHours * 3600));
		} else {
			$this->clearGrace();
		}
		$this->config->setValueString(self::APP, 'worker_secret', $new, false, true);
		$this->audit->record(Actor::Occ, 'secret.rotate', null, ['grace_hours' => $graceHours]);
		return $new;
	}

	/**
	 * `occ buscador:adopt-secret`: take the worker secret the suite generated (BD_WORKER_SECRET) instead of making one.
	 *
	 * @return bool true when the secret was adopted, false when it was already in use (a repeated provisioning run)
	 */
	public function adopt(string $secret): bool {
		if (strlen($secret) < self::MIN_SECRET) {
			throw new OpsException('The worker secret from BD_WORKER_SECRET must be at least 32 characters.');
		}
		if ($this->config->getValueString(self::APP, 'worker_secret', '') === $secret) {
			return false;
		}
		// A rotated predecessor stays valid only for a worker that still knows it; the suite's worker knows only the
		// environment's secret, so adoption ends any rotation grace instead of keeping a second valid secret around.
		$this->clearGrace();
		$this->config->setValueString(self::APP, 'worker_secret', $secret, false, true);
		$this->audit->record(Actor::Occ, 'secret.adopt', null);
		return true;
	}

	private function clearGrace(): void {
		$this->config->deleteKey(self::APP, 'worker_secret_prev');
		$this->config->deleteKey(self::APP, 'worker_secret_prev_until');
	}
}
