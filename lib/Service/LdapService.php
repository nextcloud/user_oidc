<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCP\App\IAppManager;
use OCP\IUser;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;

class LdapService {

	public function __construct(
		private LoggerInterface $logger,
		private IAppManager $appManager,
	) {
	}

	public function isLDAPEnabled(): bool {
		return $this->appManager->isEnabledForUser('user_ldap');
	}

	/**
	 * @param IUser $user
	 * @return bool
	 */
	public function isLdapDeletedUser(IUser $user): bool {
		if (!$this->isLDAPEnabled()) {
			return false;
		}

		$className = $user->getBackendClassName();
		if ($className !== 'LDAP') {
			return false;
		}

		try {
			$dui = Server::get(\OCA\User_LDAP\User\DeletedUsersIndex::class);
		} catch (ContainerExceptionInterface $e) {
			$this->logger->debug('\OCA\User_LDAP\User\DeletedUsersIndex class not found');
			return false;
		}

		if (!$dui->hasUsers()) {
			return false;
		}
		$disabledUsers = $dui->getUsers();
		$searchDisabledUser = current(
			array_filter($disabledUsers, function ($disabledUser) use ($user) {
				return $disabledUser->getUID() === $user->getUID();
			})
		);
		// did we find the user in the LDAP deleted user list?
		return $searchDisabledUser !== false;
	}

	/**
	 * This triggers User_LDAP::getLDAPUserByLoginName which does a LDAP query with the login filter
	 * so the user ID we got from the OIDC IdP should work as a login in LDAP (the login filter should use a matching attribute)
	 * @param string $userId
	 * @return void
	 */
	public function syncUser(string $userId): void {
		try {
			$ldapUserProxy = Server::get(\OCA\User_LDAP\User_Proxy::class);
			$ldapUserProxy->loginName2UserName($userId);
		} catch (ContainerExceptionInterface $e) {
			$this->logger->debug('\OCA\User_LDAP\User_Proxy class not found');
		}
	}
}
