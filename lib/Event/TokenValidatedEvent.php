<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Event;

use OCA\UserOIDC\Db\Provider;
use OCP\EventDispatcher\Event;

/**
 * This event is emitted with the bearer token that has just been validated during an API call
 *
 * It may be used for further handling of oidc authenticated requests
 */
class TokenValidatedEvent extends Event {

	public function __construct(
		private array $token,
		private Provider $provider,
		private array $discovery,
	) {
		parent::__construct();
	}

	public function getToken(): array {
		return $this->token;
	}

	public function getProvider(): Provider {
		return $this->provider;
	}

	public function getDiscovery(): array {
		return $this->discovery;
	}
}
