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
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {

	public function __construct(
		private ProviderService $providerService,
		private ID4MeService $Id4MeService,
		private IURLGenerator $urlGenerator,
		private IConfig $config,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm() {
		$this->initialStateService->provideInitialState(
			'id4meState',
			$this->Id4MeService->getID4ME()
		);
		$this->initialStateService->provideInitialState(
			'storeLoginTokenState',
			$this->config->getAppValue(Application::APP_ID, 'store_login_token', '0') === '1'
		);
		$this->initialStateService->provideInitialState(
			'providers',
			$this->providerService->getProvidersWithSettings()
		);
		$this->initialStateService->provideInitialState(
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
