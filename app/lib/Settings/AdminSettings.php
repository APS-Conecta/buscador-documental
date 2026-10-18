<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/** Administration page: a mount point for the same bundle as the search page (it shows the administration view). */
class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript('buscador_documental', 'buscador_documental-admin');
		Util::addStyle('buscador_documental', 'buscador_documental-style');
		return new TemplateResponse('buscador_documental', 'admin', [], '');
	}

	public function getSection(): string {
		return 'buscador_documental';
	}

	public function getPriority(): int {
		return 10;
	}
}
