<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
 *
 */

namespace OCA\UserOIDC\AppInfo;

use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\SettingsService;
use OCA\UserOIDC\User\Backend;
use OCP\AppFramework\App;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

class Application extends App {
	public const APP_ID = 'user_oidc';
	public const OIDC_API_REQ_HEADER = 'Authorization';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register() {
		/** @var IUserSession $userSession */
		$userSession = $this->getContainer()->query(IUserSession::class);

		/** @var IUserManager $userManager */
		$userManager = $this->getContainer()->query(IUserManager::class);

		/* Register our own user backend */
		$backend = $this->getContainer()->query(Backend::class);
		$userManager->registerBackend($backend);
		\OC_User::useBackend($backend);

		if (!$userSession->isLoggedIn()) {

			/** @var IURLGenerator $urlGenerator */
			$urlGenerator = $this->getContainer()->query(IURLGenerator::class);

			/** @var ProviderMapper $providerMapper */
			$providerMapper = $this->getContainer()->query(ProviderMapper::class);
			$providers = $providerMapper->getProviders();

			/** @var IL10N $l10n */
			$l10n = $this->getContainer()->query(IL10N::class);
			/** @var IRequest $request */
			$request = $this->getContainer()->query(IRequest::class);
			/** @var SettingsService $settings */
			$settings = $this->getContainer()->query(SettingsService::class);

			$redirectUrl = $request->getParam('redirect_url');

			// Handle immediate redirect to the oidc provider if just one is configured and no other backens are allowed
			$isDefaultLogin = false;
			try {
				$isDefaultLogin = $request->getPathInfo() === '/login' && $request->getParam('direct') !== '1';
			} catch (\Exception $e) {
				// in case any errors happen when checkinf for the path do not apply redirect logic as it is only needed for the login
			}
			if ($isDefaultLogin && !$settings->getAllowMultipleUserBackEnds() && count($providers) === 1) {
				$targetUrl = $urlGenerator->linkToRoute(self::APP_ID . '.login.login', [
					'providerId' => $providers[0]->getId(),
					'redirectUrl' => $redirectUrl
				]);
				header('Location: ' . $targetUrl);
				exit();
			}

			foreach ($providers as $provider) {
				\OC_App::registerLogIn([
					'name' => $l10n->t('Login with %1s', [$provider->getIdentifier()]),
					'href' => $urlGenerator->linkToRoute(self::APP_ID . '.login.login', ['providerId' => $provider->getId(), 'redirectUrl' => $redirectUrl]),
				]);
			}

			/** @var ID4MeService $id4meService */
			$id4meService = $this->getContainer()->query(ID4MeService::class);
			if ($id4meService->getID4ME()) {
				\OC_App::registerLogIn([
					'name' => 'ID4ME',
					'href' => $urlGenerator->linkToRoute(self::APP_ID . '.id4me.login'),
				]);
			}
			return;
		}
	}
}
