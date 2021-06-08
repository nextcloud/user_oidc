<?php

declare(strict_types=1);

/**
 * @copyright 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\UserOIDC\Settings;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
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
