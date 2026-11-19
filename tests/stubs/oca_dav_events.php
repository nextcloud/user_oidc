<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAV\Events {

	use OCP\EventDispatcher\Event;
	use Sabre\DAV\Server;

	class SabrePluginAuthInitEvent extends Event {
		public function getServer(): Server {
			return new Server();
		}
	}
}
