<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


namespace OCA\UserOIDC\Event;

use OCP\EventDispatcher\Event;

/**
 * Event to provide custom mapping logic based on the OIDC token data
 * In order to avoid further processing the event propagation should be stopped
 * in the listener after processing as the value might get overwritten afterwards
 * by other listeners through $event->stopPropagation();
 */
class AttributeMappedEvent extends Event {

	private ?string $value;

	public function __construct(
		private string $attribute,
		private object $claims,
		?string $default = null,
	) {
		parent::__construct();
		$this->value = $default;
	}

	/**
	 * @return string One of the ProviderService::SETTING_MAPPING_* constants for the attribute mapping that is currently processed
	 */
	public function getAttribute(): string {
		return $this->attribute;
	}

	/**
	 * @return object the array of claim values associated with the event
	 */
	public function getClaims(): object {
		return $this->claims;
	}

	public function hasValue() : bool {
		return ($this->value != null);
	}

	/**
	 * @return string value for the logged in user attribute
	 */
	public function getValue(): ?string {
		return $this->value;
	}

	public function setValue(?string $value): void {
		$this->value = $value;
	}
}
