<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Event {

	use OCP\EventDispatcher\Event;

	class TokenValidationRequestEvent extends Event {
		public function __construct(
			private string $accessToken,
		) {
		}

		public function getUserId(): ?string {
		}
	}
}
