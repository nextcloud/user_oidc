<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use OCA\UserOIDC\Dav\BearerBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Sabre\DAV\Auth\Plugin;

/**
 * @implements IEventListener<Event>
 */
class DavBearerAuthInitListener implements IEventListener {

	public function __construct(
		private BearerBackend $bearerBackend,
	) {
	}

	public function handle(Event $event): void {
		if (!is_a($event, \OCA\DAV\Events\SabrePluginAuthInitEvent::class)) {
			return;
		}

		$authPlugin = $event->getServer()->getPlugin('auth');
		if (!$authPlugin instanceof Plugin) {
			return;
		}

		$authPlugin->addBackend($this->bearerBackend);
	}
}
