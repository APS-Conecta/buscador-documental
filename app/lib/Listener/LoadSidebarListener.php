<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Adds the "Texto reconocido" tab script when the Files sidebar loads. The class name is matched as a string so the app
 * does not fail to load where the Files app is disabled.
 *
 * @template-implements IEventListener<Event>
 */
class LoadSidebarListener implements IEventListener {
	public const EVENT = 'OCA\\Files\\Event\\LoadSidebar';

	public function handle(Event $event): void {
		if (get_class($event) !== self::EVENT) {
			return;
		}
		Util::addScript('buscador_documental', 'buscador_documental-files-tab', 'files');
	}
}
