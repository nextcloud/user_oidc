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
 * This event is emitted with the raw token information when the login token is refreshed
 *
 * It may be used for further handling of oidc authenticated requests
 */
class TokenRefreshedEvent extends Event {

	public function __construct(
		private array $oldToken,
		private array $newToken,
		private Provider $provider,
		private array $discovery,
	) {
		parent::__construct();
	}

	public function getOldToken(): array {
		return $this->oldToken;
	}

	public function getNewToken(): array {
		return $this->newToken;
	}

	public function getProvider(): Provider {
		return $this->provider;
	}

	public function getDiscovery(): array {
		return $this->discovery;
	}
}
