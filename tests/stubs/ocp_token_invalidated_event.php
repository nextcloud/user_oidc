<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\Authentication\Events {

	use OCP\EventDispatcher\Event;

	class TokenInvalidatedEvent extends Event {
		public function getToken(): \OCP\Authentication\Token\IToken {
			throw new \RuntimeException('Stub method');
		}
	}
}
