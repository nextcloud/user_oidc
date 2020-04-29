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
use OCA\UserOIDC\Service\ID4MEService;
use OCA\UserOIDC\User\Backend;
use OCP\AppFramework\App;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

class Application extends App {

	const APPID = 'user_oidc';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APPID, $urlParams);
	}

	public function register() {
		/** @var IUserSession $userSession */
		$userSession = $this->getContainer()->query(IUserSession::class);

		/** @var IUserManager $userManager */
		$userManager = $this->getContainer()->query(IUserManager::class);

		/* Register our own user backend */
		$userManager->registerBackend($this->getContainer()->query(Backend::class));

		if (!$userSession->isLoggedIn()) {

			/** @var IURLGenerator $urlGenerator */
			$urlGenerator = $this->getContainer()->query(IURLGenerator::class);

			/** @var ProviderMapper $providerMapper */
			$providerMapper = $this->getContainer()->query(ProviderMapper::class);
			$providers = $providerMapper->getProviders();

			/** @var IL10N $l10n */
			$l10n = $this->getContainer()->query(IL10N::class);

			foreach ($providers as $provider) {
				\OC_App::registerLogIn([
					'name' => $l10n->t('Login with %1s', [$provider->getIdentifier()]),
					'href' => $urlGenerator->linkToRoute(self::APPID . '.login.login', ['providerId' => $provider->getId()]),
				]);
			}

			/** @var ID4MEService $id4meService */
			$id4meService = $this->getContainer()->query(ID4MEService::class);
			if ($id4meService->getID4ME()) {
				\OC_App::registerLogIn([
					'name' => 'ID4ME',
					'href' => $urlGenerator->linkToRoute(self::APPID . '.id4me.login'),
				]);
			}
			return;
		}
	}
}
