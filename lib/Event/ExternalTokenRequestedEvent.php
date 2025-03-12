<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Event;

use OCA\UserOIDC\Model\Token;
use OCP\EventDispatcher\Event;

/**
 * This event is emitted by other apps which need the token that was obtained when logging in Nextcloud
 */
class ExternalTokenRequestedEvent extends Event {

	private ?Token $token = null;

	public function __construct() {
		parent::__construct();
	}

	public function getToken(): ?Token {
		return $this->token;
	}

	public function setToken(?Token $token): void {
		$this->token = $token;
	}
}
