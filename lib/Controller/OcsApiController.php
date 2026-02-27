<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserManager;

class OcsApiController extends OCSController {

	public function __construct(
		IRequest $request,
		private IRootFolder $root,
		private UserMapper $userMapper,
		private IUserManager $userManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Create or update a user for a backend provider.
	 *
	 * @param int $providerId Numeric ID of the provider backend
	 * @param string $userId Provider-specific user identifier
	 * @param string|null $displayName Optional display name to set for the user
	 * @param string|null $email Optional email address to set for the user
	 * @param string|null $quota Optional quota value to set for the user
	 * @return DataResponse<Http::STATUS_OK, array{user_id: string}, array{}>
	 *
	 * 200: The user was created or updated successfully
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_provisioning'])]
	public function createUser(
		int $providerId, string $userId, ?string $displayName = null,
		?string $email = null, ?string $quota = null,
	): DataResponse {
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
	 * Delete a user created by the provider backend.
	 *
	 * @param string $userId The internal user id to delete
	 * @return DataResponse<Http::STATUS_OK, array{user_id: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The provider user was deleted successfully
	 * 404: The user was not found or is not managed by this backend
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_provisioning'])]
	public function deleteUser(string $userId): DataResponse {
		$user = $this->userManager->get($userId);
		if (is_null($user) || $user->getBackendClassName() !== Application::APP_ID) {
			return new DataResponse(['message' => 'User not found'], Http::STATUS_NOT_FOUND);
		}

		$user->delete();
		return new DataResponse(['user_id' => $userId], Http::STATUS_OK);
	}
}
