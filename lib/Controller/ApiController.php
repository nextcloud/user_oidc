<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022, Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserManager;

class ApiController extends Controller {
	/** @var UserMapper */
	private $userMapper;

	/** @var IUserManager */
	private $userManager;
	/**
	 * @var IRootFolder
	 */
	private $root;

	public function __construct(
		IRequest $request,
		IRootFolder $root,
		UserMapper $userMapper,
		IUserManager $userManager
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userMapper = $userMapper;
		$this->userManager = $userManager;
		$this->root = $root;
	}

	/**
	 * @param int $providerId
	 * @param string $userId
	 * @param string|null $displayName
	 * @param string|null $email
	 * @param string|null $quota
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/user')]
	public function createUser(int $providerId, string $userId, ?string $displayName = null,
		?string $email = null, ?string $quota = null): DataResponse {
		$backendUser = $this->userMapper->getOrCreate($providerId, $userId);
		$user = $this->userManager->get($backendUser->getUserId());

		if ($displayName) {
			if ($displayName !== $backendUser->getDisplayName()) {
				$backendUser->setDisplayName($displayName);
				$this->userMapper->update($backendUser);
			}
		}

		if ($email) {
			$user->setEMailAddress($email);
		}

		if ($quota) {
			$user->setQuota($quota);
		}

		$userFolder = $this->root->getUserFolder($user->getUID());
		try {
			// copy skeleton
			\OC_Util::copySkeleton($user->getUID(), $userFolder);
		} catch (NotPermittedException $ex) {
			// read only uses
		}

		return new DataResponse(['user_id' => $user->getUID()]);
	}
}
