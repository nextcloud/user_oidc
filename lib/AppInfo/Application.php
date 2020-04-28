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

use OCA\UserOIDC\User\Backend;
use OCP\AppFramework\App;
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

		/** @var IURLGenerator $urlGenerator */
		$urlGenerator = $this->getContainer()->query(IURLGenerator::class);

		/** @var IUserManager $userManager */
		$userManager = $this->getContainer()->query(IUserManager::class);

		$userManager->registerBackend($this->getContainer()->query(Backend::class));

		if (!$userSession->isLoggedIn()) {
			\OC_App::registerLogIn([
				'name' => 'OPENIDCONNECT',
				'href' => $urlGenerator->linkToRoute(self::APPID . '.login.login', ['providerId' => 1]),
			]);
			\OC_App::registerLogIn([
				'name' => 'ID4ME',
				'href' => $urlGenerator->linkToRoute(self::APPID . '.id4me.login'),
			]);

			return;
		}
	}
}
