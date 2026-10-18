<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(private IL10N $l, private IURLGenerator $urls) {
	}

	public function getID(): string {
		return 'buscador_documental';
	}

	public function getName(): string {
		return $this->l->t('Buscador Documental');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->urls->imagePath('buscador_documental', 'app.svg');
	}
}
