<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use OCA\UserOIDC\Dav\BearerBackend;
use OCA\UserOIDC\Dav\EphemeralPhpSession;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use Sabre\DAV\Auth\Plugin;

/**
 * @implements IEventListener<Event>
 */
class DavBearerAuthInitListener implements IEventListener {

	public function __construct(
		private BearerBackend $bearerBackend,
		private EphemeralPhpSession $ephemeralPhpSession,
		private IRequest $request,
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
		$this->ephemeralPhpSession->armIfStatelessBearer($this->request);
	}
}
