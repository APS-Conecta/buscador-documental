<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Security;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Ops\OpsException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/** `occ buscador:rotate-secret` (ADR-0015): a new worker secret now, the old one still valid for a grace period so the worker can be updated. */
class SecretRotation {
	private const APP = WorkerAuth::APP_ID;
	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	public function __construct(private IAppConfig $config, private ITimeFactory $time, private AuditLog $audit) {
	}

	/** A previous secret is still accepted right now (a second rotation would cut it off). */
	public function previousStillValid(): bool {
		$until = $this->config->getValueString(self::APP, 'worker_secret_prev_until', '');
		return strlen($this->config->getValueString(self::APP, 'worker_secret_prev', '')) >= 32
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
		if ($graceHours > 0 && strlen($old) >= 32) {
			$this->config->setValueString(self::APP, 'worker_secret_prev', $old, false, true);
			$this->config->setValueString(self::APP, 'worker_secret_prev_until', (string)($this->time->getTime() + $graceHours * 3600));
		} else {
			$this->config->deleteKey(self::APP, 'worker_secret_prev');
			$this->config->deleteKey(self::APP, 'worker_secret_prev_until');
		}
		$this->config->setValueString(self::APP, 'worker_secret', $new, false, true);
		$this->audit->record('occ', 'secret.rotate', null, ['grace_hours' => $graceHours]);
		return $new;
	}
}
