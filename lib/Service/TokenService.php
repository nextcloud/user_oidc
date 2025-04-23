<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Exception\TokenExchangeFailedException;
use OCA\UserOIDC\Model\Token;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Token management service
 * This is helpful to debug:
 * tail -f data/nextcloud.log | grep "\[Token" | jq ".time,.message"
 */
class TokenService {

	private const SESSION_TOKEN_KEY = Application::APP_ID . '-user-token';

	private IClient $client;

	public function __construct(
		IClientService $clientService,
		private ISession $session,
		private IUserSession $userSession,
		private IConfig $config,
		private LoggerInterface $logger,
		private ICrypto $crypto,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private IEventDispatcher $eventDispatcher,
		private IAppManager $appManager,
		private DiscoveryService $discoveryService,
		private ProviderMapper $providerMapper,
	) {
		$this->client = $clientService->newClient();
	}

	public function storeToken(array $tokenData): Token {
		$token = new Token($tokenData);
		$this->session->set(self::SESSION_TOKEN_KEY, json_encode($token, JSON_THROW_ON_ERROR));
		$this->logger->debug('[TokenService] Store token');
		return $token;
	}

	/**
	 * Get the token stored in the session
	 * If it has expired: try to refresh it
	 *
	 * @param bool $refreshIfExpired
	 * @return Token|null Return a token only if it is valid or has been successfully refreshed
	 * @throws \JsonException
	 */
	public function getToken(bool $refreshIfExpired = true): ?Token {
		$sessionData = $this->session->get(self::SESSION_TOKEN_KEY);
		if (!$sessionData) {
			$this->logger->debug('[TokenService] getToken: no session data');
			return null;
		}

		$token = new Token(json_decode($sessionData, true, 512, JSON_THROW_ON_ERROR));
		// token is still valid
		if (!$token->isExpired()) {
			$this->logger->debug('[TokenService] getToken: token is still valid, it expires in ' . $token->getExpiresInFromNow() . ' and refresh expires in ' . $token->getRefreshExpiresInFromNow());
			return $token;
		}

		// token has expired
		// try to refresh the token if there is a refresh token and it is still valid
		if ($refreshIfExpired && $token->getRefreshToken() !== null && !$token->refreshIsExpired()) {
			$this->logger->debug('[TokenService] getToken: token is expired and refresh token is still valid, refresh expires in ' . $token->getRefreshExpiresInFromNow());
			return $this->refresh($token);
		}

		$this->logger->debug('[TokenService] getToken: return a token that has not been refreshed');
		return $token;
	}

	/**
	 * Check to make sure the login token is still valid
	 *
	 * @return void
	 * @throws \JsonException
	 * @throws PreConditionNotMetException
	 */
	public function checkLoginToken(): void {
		$storeLoginTokenEnabled = $this->config->getAppValue(Application::APP_ID, 'store_login_token', '0') === '1';
		if (!$storeLoginTokenEnabled) {
			return;
		}

		$currentUser = $this->userSession->getUser();
		if (!$this->userSession->isLoggedIn() || $currentUser === null) {
			$this->logger->debug('[TokenService] checkLoginToken: user not logged in');
			return;
		}
		if ($this->config->getUserValue($currentUser->getUID(), Application::APP_ID, 'had_token_once', '0') !== '1') {
			$this->logger->debug('[TokenService] checkLoginToken: we never had a token before, check not needed');
			return;
		}

		$token = $this->getToken();
		if ($token === null) {
			$this->logger->debug('[TokenService] checkLoginToken: token is null');
			// if we don't have a token but we had one once,
			// it means the session (where we store the token) has died
			// so we need to reauthenticate
			$this->logger->debug('[TokenService] checkLoginToken: token is null and user had_token_once -> logout');
			$this->userSession->logout();
		} elseif ($token->isExpired()) {
			$this->logger->debug('[TokenService] checkLoginToken: token is still expired -> reauthenticate');
			// if the token is not valid, it means we couldn't refresh it so we need to reauthenticate to get a fresh token
			$this->reauthenticate($token->getProviderId());
		}
	}

	public function reauthenticate(int $providerId) {
		// Logout the user and redirect to the oidc login flow to gather a fresh token
		$this->userSession->logout();
		$redirectUrl = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.login', [
			'providerId' => $providerId,
			'redirectUrl' => $this->request->getRequestUri(),
		]);
		header('Location: ' . $redirectUrl);
		$this->logger->debug('[TokenService] reauthenticate', ['redirectUrl' => $redirectUrl]);
		exit();
	}

	/**
	 * @param Token $token
	 * @return Token
	 * @throws \JsonException
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function refresh(Token $token): Token {
		$oidcProvider = $this->providerMapper->getProvider($token->getProviderId());
		$discovery = $this->discoveryService->obtainDiscovery($oidcProvider);

		try {
			$clientSecret = $oidcProvider->getClientSecret();
			if ($clientSecret !== '') {
				try {
					$clientSecret = $this->crypto->decrypt($clientSecret);
				} catch (\Exception $e) {
					$this->logger->error('[TokenService] Failed to decrypt oidc client secret to refresh the token');
				}
			}
			$this->logger->debug('[TokenService] Refreshing the token: ' . $discovery['token_endpoint']);
			$result = $this->client->post(
				$discovery['token_endpoint'],
				[
					'body' => [
						'client_id' => $oidcProvider->getClientId(),
						'client_secret' => $clientSecret,
						'grant_type' => 'refresh_token',
						'refresh_token' => $token->getRefreshToken(),
					],
				]
			);
			$this->logger->debug('[TokenService] Token refresh request params', [
				'client_id' => $oidcProvider->getClientId(),
				// 'client_secret' => $clientSecret,
				'grant_type' => 'refresh_token',
				// 'refresh_token' => $token->getRefreshToken(),
			]);
			$body = $result->getBody();
			$bodyArray = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
			$this->logger->debug('[TokenService] ---- Refresh token success');
			return $this->storeToken(
				array_merge(
					$bodyArray,
					['provider_id' => $token->getProviderId()],
				)
			);
		} catch (\Exception $e) {
			$this->logger->error('[TokenService] Failed to refresh token ', ['exception' => $e]);
			// Failed to refresh, return old token which will be retried or otherwise timeout if expired
			return $token;
		}
	}

	public function decodeIdToken(Token $token): array {
		$provider = $this->providerMapper->getProvider($token->getProviderId());
		$jwks = $this->discoveryService->obtainJWK($provider, $token->getIdToken());
		JWT::$leeway = 60;
		$idTokenObject = JWT::decode($token->getIdToken(), $jwks);
		return json_decode(json_encode($idTokenObject), true);
	}

	/**
	 * Exchange the login token for another audience (client ID)
	 *
	 * @param string $targetAudience
	 * @return Token
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws TokenExchangeFailedException
	 * @throws \JsonException
	 */
	public function getExchangedToken(string $targetAudience, array $extraScopes = []): Token {
		$storeLoginTokenEnabled = $this->config->getAppValue(Application::APP_ID, 'store_login_token', '0') === '1';
		if (!$storeLoginTokenEnabled) {
			throw new TokenExchangeFailedException(
				'Failed to exchange token, storing the login token is disabled. It can be enabled in config.php',
				0,
			);
		}

		$loginToken = $this->getToken();
		if ($loginToken === null) {
			$this->logger->debug('[TokenService] Failed to exchange token, no login token found in the session');
			throw new TokenExchangeFailedException('Failed to exchange token, no login token found in the session');
		}
		if ($loginToken->isExpired()) {
			$this->logger->debug('[TokenService] Failed to exchange token, the login token is expired');
			throw new TokenExchangeFailedException('Failed to exchange token, the login token is expired');
		}
		$oidcProvider = $this->providerMapper->getProvider($loginToken->getProviderId());
		$discovery = $this->discoveryService->obtainDiscovery($oidcProvider);
		$scope = $oidcProvider->getScope();
		if (!empty($extraScopes)) {
			$scope .= ' ' . implode(' ', $extraScopes);
		}

		try {
			$clientSecret = $oidcProvider->getClientSecret();
			if ($clientSecret !== '') {
				try {
					$clientSecret = $this->crypto->decrypt($clientSecret);
				} catch (\Exception $e) {
					$this->logger->error('[TokenService] Token Exchange: Failed to decrypt oidc client secret');
				}
			}
			$this->logger->debug('[TokenService] Exchanging the token: ' . $discovery['token_endpoint']);
			// more in https://www.keycloak.org/securing-apps/token-exchange
			$result = $this->client->post(
				$discovery['token_endpoint'],
				[
					'body' => [
						'client_id' => $oidcProvider->getClientId(),
						'client_secret' => $clientSecret,
						'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
						'subject_token' => $loginToken->getAccessToken(),
						'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
						// can also be
						// urn:ietf:params:oauth:token-type:access_token
						// or urn:ietf:params:oauth:token-type:id_token
						// this one will get us an access token and refresh token within the response
						'requested_token_type' => 'urn:ietf:params:oauth:token-type:refresh_token',
						'audience' => $targetAudience,
						'scope' => $scope,
					],
				]
			);
			$this->logger->debug('[TokenService] Token exchange request params', [
				'client_id' => $oidcProvider->getClientId(),
				// 'client_secret' => $clientSecret,
				'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
				// 'subject_token' => $loginToken->getAccessToken(),
				'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
				'requested_token_type' => 'urn:ietf:params:oauth:token-type:refresh_token',
				'audience' => $targetAudience,
			]);
			$body = $result->getBody();
			$bodyArray = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
			$this->logger->debug('[TokenService] Token exchange success: "' . trim($body) . '"');
			$tokenData = array_merge(
				$bodyArray,
				['provider_id' => $loginToken->getProviderId()],
			);
			return new Token($tokenData);
		} catch (ClientException|ServerException $e) {
			$response = $e->getResponse();
			$body = (string)$response->getBody();
			$this->logger->error('[TokenService] Failed to exchange token, client/server error in the exchange request', ['response_body' => $body, 'exception' => $e]);

			$parsedBody = json_decode(trim($body), true);
			if (is_array($parsedBody) && isset($parsedBody['error'], $parsedBody['error_description'])) {
				throw new TokenExchangeFailedException(
					'Failed to exchange token, client/server error in the exchange request: ' . $body,
					0,
					$e,
					$parsedBody['error'],
					$parsedBody['error_description'],
				);
			} else {
				throw new TokenExchangeFailedException(
					'Failed to exchange token, client/server error in the exchange request: ' . $body,
					0,
					$e,
				);
			}
		} catch (\Exception|\Throwable $e) {
			$this->logger->error('[TokenService] Failed to exchange token ', ['exception' => $e]);
			throw new TokenExchangeFailedException('Failed to exchange token, error in the exchange request', 0, $e);
		}
	}

	/**
	 * Try to get a token from the Oidc provider app for a user and a specific audience (client ID)
	 *
	 * @param string $userId
	 * @param string $targetAudience
	 * @return Token|null
	 */
	public function getTokenFromOidcProviderApp(string $userId, string $targetAudience, array $extraScopes = [], string $resource = ''): ?Token {
		if (!class_exists(\OCA\OIDCIdentityProvider\AppInfo\Application::class)) {
			$this->logger->warning('[TokenService] Failed to get token from Oidc provider app, oidc app is not installed');
			return null;
		}
		if (!$this->appManager->isEnabledForUser(\OCA\OIDCIdentityProvider\AppInfo\Application::APP_ID)) {
			$this->logger->warning('[TokenService] Failed to get token from Oidc provider app, oidc app is not enabled');
			return null;
		}
		if (!class_exists(\OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent::class)) {
			$this->logger->warning('[TokenService] Failed to get token from Oidc provider app, TokenGenerationRequestEvent class not found, oidc app is probably not >= v1.4.0');
			return null;
		}

		try {
			$scope = implode(' ', $extraScopes);
			$generationEvent = new \OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent($targetAudience, $userId, $scope, $resource);
			$this->eventDispatcher->dispatchTyped($generationEvent);
			if ($generationEvent->getAccessToken() === null || $generationEvent->getIdToken() === null) {
				$this->logger->debug('[TokenService] The Oidc provider app did not generate any access/id token');
				return null;
			}
		} catch (\Exception|\Throwable $e) {
			$this->logger->debug('[TokenService] The Oidc provider app failed to generate a token');
			return null;
		}

		return new Token([
			'access_token' => $generationEvent->getAccessToken(),
			'id_token' => $generationEvent->getIdToken(),
			'refresh_token' => $generationEvent->getRefreshToken(),
			'expires_in' => $generationEvent->getExpiresIn(),
			// the getRefreshExpiresIn method will appear after oidc v1.4.0, see https://github.com/H2CK/oidc/pull/530
			'refresh_expires_in' => method_exists($generationEvent, 'getRefreshExpiresIn')
				? $generationEvent->getRefreshExpiresIn()
				: $generationEvent->getExpiresIn(),
		]);
	}
}
