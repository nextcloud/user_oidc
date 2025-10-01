<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\JwkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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
		private JwkService $jwkService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	public function createUser(
		int $providerId,
		string $userId,
		?string $displayName = null,
		?string $email = null,
		?string $quota = null,
	): DataResponse {
		$backendUser = $this->userMapper->getOrCreate($providerId, $userId);
		$user = $this->userManager->get($backendUser->getUserId());

		// Update display name if provided and different
		if ($displayName !== null && $displayName !== $backendUser->getDisplayName()) {
			$backendUser->setDisplayName($displayName);
			$this->userMapper->update($backendUser);
		}

		// Update email if provided
		if ($email !== null) {
			$user->setSystemEMailAddress($email);
		}

		// Update quota if provided
		if ($quota !== null) {
			$user->setQuota($quota);
		}

		// Copy skeleton files to user folder
		$userFolder = $this->root->getUserFolder($user->getUID());
		try {
			\OC_Util::copySkeleton($user->getUID(), $userFolder);
		} catch (NotPermittedException $e) {
			// Silently ignore for read-only users
		}

		return new DataResponse(['user_id' => $user->getUID()]);
	}

	#[NoCSRFRequired]
	public function deleteUser(string $userId): DataResponse {
		$user = $this->userManager->get($userId);

		if ($user === null || $user->getBackendClassName() !== Application::APP_ID) {
			return new DataResponse(['message' => 'User not found'], Http::STATUS_NOT_FOUND);
		}

		$user->delete();
		return new DataResponse(['user_id' => $userId], Http::STATUS_OK);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function getJwks(): DataResponse {
		$jwks = $this->jwkService->getJwks();
		return new DataResponse([
			'keys' => [
				$jwks['public'],
			],
		]);
	}
}
