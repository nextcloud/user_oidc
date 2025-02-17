<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use OCA\UserOIDC\Event\ExchangedTokenRequestedEvent;
use OCA\UserOIDC\Service\TokenService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<ExchangedTokenRequestedEvent|Event>
 */
class ExchangedTokenRequestedListener implements IEventListener {

	public function __construct(
		private IUserSession $userSession,
		private TokenService $tokenService,
		private LoggerInterface $logger,
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ExchangedTokenRequestedEvent) {
			return;
		}

		if (!$this->userSession->isLoggedIn()) {
			return;
		}

		$targetAudience = $event->getTargetAudience();
		$this->logger->debug('[TokenExchange Listener] received request for audience: ' . $targetAudience);

		// generate a token pair with the Oidc provider app
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$ncProviderTokenGenerationEnabled = (isset($oidcSystemConfig['oidc_provider_token_generation']) && $oidcSystemConfig['oidc_provider_token_generation'] === true);
		if ($ncProviderTokenGenerationEnabled) {
			$userId = $this->userSession->getUser()?->getUID();
			if ($userId !== null) {
				$ncProviderToken = $this->tokenService->getTokenFromOidcProviderApp($userId, $targetAudience);
				if ($ncProviderToken !== null) {
					$event->setToken($ncProviderToken);
					return;
				}
			}
		}

		// classic token exchange with an external provider
		$token = $this->tokenService->getExchangedToken($targetAudience);
		$event->setToken($token);
	}
}
