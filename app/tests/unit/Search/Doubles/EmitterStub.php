<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OC\Hooks;

// Nextcloud core's interface, absent from the OCP package; IRootFolder extends it, so doubles of IRootFolder need it.
if (!interface_exists(Emitter::class, false)) {
	interface Emitter {
		public function listen($scope, $method, callable $callback);

		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}
