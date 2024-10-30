<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Settings;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {

	/** @var ProviderService */
	private $providerService;

	/** @var ID4MeService */
	private $Id4MeService;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var IInitialStateService */
	private $initialStateService;

	public function __construct(ProviderService $providerService,
		ID4MeService $ID4MEService,
		IURLGenerator $urlGenerator,
		IInitialStateService $initialStateService) {
		$this->providerService = $providerService;
		$this->Id4MeService = $ID4MEService;
		$this->urlGenerator = $urlGenerator;
		$this->initialStateService = $initialStateService;
	}

	public function getForm() {
		$this->initialStateService->provideInitialState(
			Application::APP_ID,
			'id4meState',
			$this->Id4MeService->getID4ME()
		);
		$this->initialStateService->provideInitialState(
			Application::APP_ID,
			'providers',
			$this->providerService->getProvidersWithSettings()
		);
		$this->initialStateService->provideInitialState(
			Application::APP_ID,
			'redirectUrl',
			$this->urlGenerator->linkToRouteAbsolute('user_oidc.login.code')
		);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin-settings');

		return new TemplateResponse(Application::APP_ID, 'admin-settings');
	}

	public function getSection() {
		return Application::APP_ID;
	}

	public function getPriority() {
		return 90;
	}
}
