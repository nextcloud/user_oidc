<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserManager;

class ApiController extends Controller {

	public function __construct(
		IRequest $request,
		private IRootFolder $root,
		private UserMapper $userMapper,
		private IUserManager $userManager,
	) {
		parent::__construct(Application::APP_ID, $request);
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
			$user->setSystemEMailAddress($email);
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

	/**
	 * @param string $userId
	 * @return DataResponse
	 */
	#[NoCSRFRequired]
	public function deleteUser(string $userId): DataResponse {
		$user = $this->userManager->get($userId);
		if (is_null($user) || $user->getBackendClassName() !== Application::APP_ID) {
			return new DataResponse(['message' => 'User not found'], Http::STATUS_NOT_FOUND);
		}

		$user->delete();
		return new DataResponse(['user_id' => $userId], Http::STATUS_OK);
	}
}
