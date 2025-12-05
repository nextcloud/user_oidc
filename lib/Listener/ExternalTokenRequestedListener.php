<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Event\ExternalTokenRequestedEvent;
use OCA\UserOIDC\Exception\GetExternalTokenFailedException;
use OCA\UserOIDC\Service\TokenService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<ExternalTokenRequestedEvent|Event>
 */
class ExternalTokenRequestedListener implements IEventListener {

	public function __construct(
		private IUserSession $userSession,
		private TokenService $tokenService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ExternalTokenRequestedEvent) {
			return;
		}

		if (!$this->userSession->isLoggedIn()) {
			return;
		}

		$this->logger->debug('[ExternalTokenRequestedListener] received request');

		$storeLoginTokenEnabled = $this->appConfig->getValueString(Application::APP_ID, 'store_login_token', '0', lazy: true) === '1';
		if (!$storeLoginTokenEnabled) {
			throw new GetExternalTokenFailedException('Failed to get external token, login token is not stored', 0);
		}

		$token = $this->tokenService->getToken();
		$event->setToken($token);
	}
}
