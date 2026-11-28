<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCA\UserOIDC\User\Backend;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\DAV\Auth\Backend\AbstractBearer;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class BearerBackend extends AbstractBearer {

	public function __construct(
		private Backend $userBackend,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private DavRequestUser $davRequestUser,
	) {
	}

	protected function validateBearerToken($bearerToken) {
		if (!is_string($bearerToken) || $bearerToken === '') {
			return false;
		}

		$uid = $this->userBackend->resolveBearerUserId(false);
		if ($uid === '') {
			return false;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return false;
		}

		$this->davRequestUser->bind($this->userSession, $user);
		$this->setupFilesystem($uid);

		return 'principals/users/' . $uid;
	}

	protected function setupFilesystem(string $uid): void {
		\OC_Util::setupFS($uid);
	}

	// Let the stock bearer backend emit the 401 some DAV clients expect.
	public function challenge(RequestInterface $request, ResponseInterface $response): void {
	}
}
