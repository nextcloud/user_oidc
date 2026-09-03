<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Event;

use OCA\UserOIDC\Db\Provider;
use OCP\EventDispatcher\Event;

/**
 * This event is emitted with the raw token information when the login token is obtained
 * on a successful login and when it is refreshed
 *
 * It may be used by other apps to make use of the login token and stay informed when it gets refreshed
 *
 * The tokens are arrays with this shape:
 * [
 *     'id_token' => '...',
 *     'access_token' => '...',
 *     'refresh_token' => '...',
 *     'expires_in' => 3600,
 *     'refresh_expires_in' => 3600,
 *     'created_at' => 123456789,
 * ]
 */
class UserObtainedTokenEvent extends Event {

	public function __construct(
		private string $userId,
		private ?array $oldToken,
		private array $newToken,
		private Provider $provider,
		private array $discovery,
	) {
		parent::__construct();
	}

	/**
	 * @return string The user ID of the user who obtained the token
	 */
	public function getUserId(): string {
		return $this->userId;
	}

	/**
	 * This old token is set only when the token is refreshed
	 * @return array|null The old token that has been refreshed
	 */
	public function getOldToken(): ?array {
		return $this->oldToken;
	}

	/**
	 * @return array The freshly obtained token
	 */
	public function getNewToken(): array {
		return $this->newToken;
	}

	/**
	 * @return Provider The related Oidc provider
	 */
	public function getProvider(): Provider {
		return $this->provider;
	}

	/**
	 * The decoded discovery data from the discovery endpoint payload
	 * @return array The discovery data
	 */
	public function getDiscovery(): array {
		return $this->discovery;
	}
}
