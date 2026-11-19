<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;

// Trashbin::restore reads user_id from the session, not IUserSession::getUser.
class DavRequestUser {
	public function __construct(
		private EphemeralPhpSession $ephemeralPhpSession,
	) {
	}

	private ?IUserSession $fallback = null;

	public function bind(IUserSession $userSession, IUser $user): bool {
		$overlaid = $this->installOverlay($userSession);
		$userSession->setUser($user);
		if (method_exists($userSession, 'setLoginName')) {
			$userSession->setLoginName($user->getUID());
		}
		if (!$overlaid) {
			$this->scheduleFallbackClear($userSession);
		}
		return $overlaid;
	}

	public function releaseFallback(): void {
		$userSession = $this->fallback;
		$this->fallback = null;
		if ($userSession === null) {
			return;
		}
		$userSession->setUser(null);
		if (method_exists($userSession, 'setLoginName')) {
			$userSession->setLoginName(null);
		}
	}

	private function installOverlay(IUserSession $userSession): bool {
		if (!method_exists($userSession, 'getSession') || !method_exists($userSession, 'setSession')) {
			return false;
		}
		$inner = $userSession->getSession();
		if (!$inner instanceof ISession) {
			return false;
		}
		if ($inner instanceof RequestUserOverlay) {
			return true;
		}
		$userSession->setSession(new RequestUserOverlay($inner));
		return true;
	}

	private function scheduleFallbackClear(IUserSession $userSession): void {
		$this->fallback = $userSession;
		$this->ephemeralPhpSession->runBeforeDiscard(function (): void {
			$this->releaseFallback();
		});
	}
}
