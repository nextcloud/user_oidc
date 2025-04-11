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
 * This event is emitted by other apps which need a token from the internal Oidc provider (the "oidc" app)
 */
class InternalTokenRequestedEvent extends Event {

	private ?Token $token = null;

	public function __construct(
		private string $targetAudience,
		private array $extraScopes = [],
		private string $resource = '',
	) {
		parent::__construct();
	}

	public function getTargetAudience(): string {
		return $this->targetAudience;
	}

	public function setTargetAudience(string $targetAudience): void {
		$this->targetAudience = $targetAudience;
	}

	public function getExtraScopes(): array {
		return $this->extraScopes;
	}

	public function getResource(): string {
		return $this->resource;
	}

	public function getToken(): ?Token {
		return $this->token;
	}

	public function setToken(?Token $token): void {
		$this->token = $token;
	}
}
