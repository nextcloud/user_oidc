<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\SessionMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\DB\Exception;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TokenInvalidatedEvent|Event>
 */
class TokenInvalidatedListener implements IEventListener {

	public function __construct(
		private LoggerInterface $logger,
		private SessionMapper $sessionMapper,
		private ProviderMapper $providerMapper,
		private DiscoveryService $discoveryService,
		private IURLGenerator $urlGenerator,
		private HttpClientHelper $httpClientHelper,
		private ICrypto $crypto,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof TokenInvalidatedEvent) {
			return;
		}

		$eventToken = $event->getToken();
		$eventTokenId = $eventToken->getId();
		$eventTokenUserId = $eventToken->getUID();

		$this->logger->debug('[TokenInvalidatedListener] received event', [
			'token_id' => $eventTokenId,
			'user_id' => $eventTokenUserId,
		]);

		try {
			$oidcSession = $this->sessionMapper->getSessionByAuthTokenAndUid($eventTokenId, $eventTokenUserId);
		} catch (Exception|DoesNotExistException|MultipleObjectsReturnedException $e) {
			$this->logger->debug('[TokenInvalidatedListener] Could not find the OIDC session related with an invalidated token', [
				'token_id' => $eventTokenId,
				'user_id' => $eventTokenUserId,
				'exception' => $e,
			]);
			return;
		}
		// we have nothing to do if we know the idp session is already closed
		if ($oidcSession->getIdpSessionClosed() !== 0) {
			$this->logger->debug('[TokenInvalidatedListener] The session is already closed on the IdP side', [
				'token_id' => $eventTokenId,
				'user_id' => $eventTokenUserId,
			]);
			return;
		}

		// now we call the end_session_endpoint
		try {
			$provider = $this->providerMapper->getProvider($oidcSession->getProviderId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			$this->logger->warning('[TokenInvalidatedListener] Could not find the OIDC provider of a session related with an invalidated token', [
				'token_id' => $eventTokenId,
				'user_id' => $eventTokenUserId,
				'provider_id' => $oidcSession->getProviderId(),
				'exception' => $e,
			]);
			return;
		}

		// Check if a custom end_session_endpoint is set in the provider otherwise use the default one provided by the openid-configuration
		$discoveryData = $this->discoveryService->obtainDiscovery($provider);
		$defaultEndSessionEndpoint = $discoveryData['end_session_endpoint'] ?? null;
		$customEndSessionEndpoint = $provider->getEndSessionEndpoint();
		$endSessionEndpoint = $customEndSessionEndpoint ?: $defaultEndSessionEndpoint;

		if ($endSessionEndpoint === null || $endSessionEndpoint === '') {
			$this->logger->warning('[TokenInvalidatedListener] Could not find the end_session_endpoint of the OIDC provider of a session related with an invalidated token', [
				'token_id' => $eventTokenId,
				'user_id' => $eventTokenUserId,
				'provider_id' => $oidcSession->getProviderId(),
			]);
			return;
		}

		try {
			$decryptedIdToken = $this->crypto->decrypt($oidcSession->getIdToken());
		} catch (\Exception $e) {
			$this->logger->warning('[TokenInvalidatedListener] Could not decrpyt the login id token of a session related with an invalidated token', ['exception' => $e]);
			return;
		}
		$endSessionEndpoint .= '?post_logout_redirect_uri=' . $this->urlGenerator->getAbsoluteURL('/');
		$endSessionEndpoint .= '&client_id=' . $provider->getClientId();
		$endSessionEndpoint .= '&id_token_hint=' . $decryptedIdToken;

		$this->logger->debug('[TokenInvalidatedListener] requesting ' . $endSessionEndpoint);
		try {
			$this->httpClientHelper->get($endSessionEndpoint, [], ['timeout' => 5]);
		} catch (ClientException|ServerException $e) {
			$response = $e->getResponse();
			$body = (string)$response->getBody();
			$this->logger->debug('[TokenInvalidatedListener] Failed to request the end_session_endpoint, client or server error', [
				'status_code' => $response->getStatusCode(),
				'body' => $body,
				'exception' => $e,
			]);
		} catch (\Exception $e) {
			$this->logger->debug('[TokenInvalidatedListener] Failed to request the end_session_endpoint', ['exception' => $e]);
		}
		// we know this oidc session is not useful anymore, we can delete it
		$this->sessionMapper->delete($oidcSession);
	}
}
