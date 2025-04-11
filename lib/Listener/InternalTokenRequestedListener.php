<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use OCA\UserOIDC\Event\InternalTokenRequestedEvent;
use OCA\UserOIDC\Service\TokenService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<InternalTokenRequestedEvent|Event>
 */
class InternalTokenRequestedListener implements IEventListener {

	public function __construct(
		private IUserSession $userSession,
		private TokenService $tokenService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof InternalTokenRequestedEvent) {
			return;
		}

		if (!$this->userSession->isLoggedIn()) {
			return;
		}

		$targetAudience = $event->getTargetAudience();
		$extraScopes = $event->getExtraScopes();
		$resource = $event->getResource();
		$this->logger->debug('[InternalTokenRequestedListener] received request for audience: ' . $targetAudience);

		// generate a token pair with the Oidc provider app
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId !== null) {
			$ncProviderToken = $this->tokenService->getTokenFromOidcProviderApp($userId, $targetAudience, $extraScopes, $resource);
			if ($ncProviderToken !== null) {
				$event->setToken($ncProviderToken);
			}
		}
	}
}
