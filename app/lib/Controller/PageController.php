<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Controller;

use OCA\BuscadorDocumental\Search\Searcher;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/** The app page (/apps/buscador_documental/): a shell plus the initial state the Vue app starts from. */
class PageController extends Controller {
	public function __construct(IRequest $request, private IInitialState $state, private Searcher $searcher, private IUserSession $session, private IGroupManager $groups) {
		parent::__construct('buscador_documental', $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$uid = $this->session->getUser()?->getUID() ?? '';
		$this->state->provideInitialState('modes', $this->searcher->modes());
		$this->state->provideInitialState('readiness', $this->searcher->readiness());
		$this->state->provideInitialState('is_admin', $uid !== '' && $this->groups->isAdmin($uid));
		Util::addScript('buscador_documental', 'buscador_documental-main');
		Util::addStyle('buscador_documental', 'buscador_documental-style');
		return new TemplateResponse('buscador_documental', 'index');
	}
}
