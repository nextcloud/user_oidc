<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\User_LDAP\User;

class OfflineUser {
	public function getUID(): string {
		return '';
	}
}

class DeletedUsersIndex {
	public function hasUsers(): bool {
		return false;
	}

	/**
	 * @return list<OfflineUser>
	 */
	public function getUsers(): array {
		return [];
	}
}

namespace OCA\User_LDAP;

class User_Proxy {
	/**
	 * @return mixed
	 */
	public function loginName2UserName(string $userId) {
		return null;
	}
}
