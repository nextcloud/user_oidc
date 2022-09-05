<?php

/** @noinspection AdditionOperationOnArraysInspection */

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Controller;

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OCA\UserOIDC\Db\SessionMapper;
use OCA\UserOIDC\Event\TokenObtainedEvent;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\Session\Exceptions\SessionNotAvailableException;

class LoginController extends Controller {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	public const PROVIDERID = 'oidc.providerid';
	private const REDIRECT_AFTER_LOGIN = 'oidc.redirect';
	private const ID_TOKEN = 'oidc.id_token';

	/** @var ISecureRandom */
	private $random;

	/** @var ISession */
	private $session;

	/** @var IClientService */
	private $clientService;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var IUserSession */
	private $userSession;

	/** @var IUserManager */
	private $userManager;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var ProviderMapper */
	private $providerMapper;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var ILogger */
	private $logger;

	/** @var ProviderService */
	private $providerService;

	/** @var DiscoveryService */
	private $discoveryService;

	/** @var IConfig */
	private $config;

	/** @var LdapService */
	private $ldapService;

	/** @var IProvider */
	private $authTokenProvider;

	/** @var SessionMapper */
	private $sessionMapper;

	/** @var ProvisioningService */
	private $provisioningService;

	public function __construct(
		IRequest $request,
		ProviderMapper $providerMapper,
		ProviderService $providerService,
		DiscoveryService $discoveryService,
		LdapService $ldapService,
		ISecureRandom $random,
		ISession $session,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		IUserManager $userManager,
		ITimeFactory $timeFactory,
		IEventDispatcher $eventDispatcher,
		IConfig $config,
		IProvider $authTokenProvider,
		SessionMapper $sessionMapper,
		ProvisioningService $provisioningService,
		ILogger $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->discoveryService = $discoveryService;
		$this->urlGenerator = $urlGenerator;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->timeFactory = $timeFactory;
		$this->providerMapper = $providerMapper;
		$this->providerService = $providerService;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
		$this->config = $config;
		$this->ldapService = $ldapService;
		$this->authTokenProvider = $authTokenProvider;
		$this->sessionMapper = $sessionMapper;
		$this->provisioningService = $provisioningService;
		$this->request = $request;
	}

	/**
	 * @return bool
	 */
	private function isSecure(): bool {
		// no restriction in debug mode
		return $this->config->getSystemValueBool('debug', false) || $this->request->getServerProtocol() === 'https';
	}

	/**
	 * @return TemplateResponse
	 */
	private function generateProtocolErrorResponse(): TemplateResponse {
		$response = new TemplateResponse('', 'error', [
			'errors' => [
				['error' => 'You must access Nextcloud with HTTPS to use OpenID Connect.']
			]
		], TemplateResponse::RENDER_AS_ERROR);
		$response->setStatus(Http::STATUS_NOT_FOUND);
		return $response;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param int $providerId
	 * @param string|null $redirectUrl
	 * @return DataDisplayResponse|RedirectResponse|TemplateResponse
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function login(int $providerId, string $redirectUrl = null) {
		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse($redirectUrl);
		}
		if (!$this->isSecure()) {
			return $this->generateProtocolErrorResponse();
		}
		$this->logger->debug('Initiating login for provider with id: ' . $providerId);

		//TODO: handle exceptions
		$provider = $this->providerMapper->getProvider($providerId);

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);
		$this->session->set(self::REDIRECT_AFTER_LOGIN, $redirectUrl);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$this->session->set(self::PROVIDERID, $providerId);
		$this->session->close();

		// get attribute mapping settings
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');

		$claims = [
			// more details about requesting claims:
			// https://openid.net/specs/openid-connect-core-1_0.html#IndividualClaimsRequests
			'id_token' => [
				// ['essential' => true] means it's mandatory but it won't trigger an error if it's not there
				// null means we want it
				$emailAttribute => null,
				$displaynameAttribute => null,
				$quotaAttribute => null,
				$groupsAttribute => null,
			],
			'userinfo' => [
				$emailAttribute => null,
				$displaynameAttribute => null,
				$quotaAttribute => null,
				$groupsAttribute => null,
			],
		];

		if ($uidAttribute !== 'sub') {
			$claims['id_token'][$uidAttribute] = ['essential' => true];
			$claims['userinfo'][$uidAttribute] = ['essential' => true];
		}

		$extraClaimsString = $this->providerService->getSetting($providerId, ProviderService::SETTING_EXTRA_CLAIMS, '');
		if ($extraClaimsString) {
			$extraClaims = explode(' ', $extraClaimsString);
			foreach ($extraClaims as $extraClaim) {
				$claims['id_token'][$extraClaim] = null;
				$claims['userinfo'][$extraClaim] = null;
			}
		}

		$data = [
			'client_id' => $provider->getClientId(),
			'response_type' => 'code',
			'scope' => $provider->getScope(),
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
			'claims' => json_encode($claims),
			'state' => $state,
			'nonce' => $nonce,
		];
		// pass discovery query parameters also on to the authentication
		$discoveryUrl = parse_url($provider->getDiscoveryEndpoint());
		if (isset($discoveryUrl["query"])) {
			$this->logger->debug('Add custom discovery query: ' . $discoveryUrl["query"]);
			$discoveryQuery = [];
			parse_str($discoveryUrl["query"], $discoveryQuery);
			$data += $discoveryQuery;
		}

		try {
			$discovery = $this->discoveryService->obtainDiscovery($provider);
		} catch (\Exception $e) {
			$this->logger->error('Could not reach the provider at URL ' . $provider->getDiscoveryEndpoint());
			$response = new TemplateResponse('', 'error', [
				'errors' => [
					['error' => 'Could not reach the OpenID Connect provider.'],
				],
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}

		$authorizationUrl = $discovery['authorization_endpoint'] . '?' . http_build_query($data);
		// check if the authorization_endpoint is a valid URL
		if (filter_var($discovery['authorization_endpoint'], FILTER_VALIDATE_URL) === false) {
			$this->logger->error('Invalid authorization_endpoint URL: ' . $discovery['authorization_endpoint']);
			$response = new TemplateResponse('', 'error', [
				'errors' => [
					['error' => 'Invalid authorization_endpoint URL: ' . $discovery['authorization_endpoint']],
				],
			], TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}

		$this->logger->debug('Redirecting user to: ' . $authorizationUrl);

		// Workaround to avoid empty session on special conditions in Safari
		// https://github.com/nextcloud/user_oidc/pull/358
		if ($this->request->isUserAgent(['/Safari/']) && !$this->request->isUserAgent(['/Chrome/'])) {
			return new DataDisplayResponse('<meta http-equiv="refresh" content="0; url=' . $authorizationUrl . '" />');
		}

		return new RedirectResponse($authorizationUrl);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $state
	 * @param string $code
	 * @param string $scope
	 * @param string $error
	 * @param string $error_description
	 * @return JSONResponse|RedirectResponse|TemplateResponse
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws SessionNotAvailableException
	 * @throws \JsonException
	 */
	public function code(string $state = '', string $code = '', string $scope = '', string $error = '', string $error_description = '') {
		if (!$this->isSecure()) {
			return $this->generateProtocolErrorResponse();
		}
		$this->logger->debug('Code login with core: ' . $code . ' and state: ' . $state);

		if ($error !== '') {
			return new JSONResponse([
				'error' => $error,
				'error_description' => $error_description,
			], Http::STATUS_FORBIDDEN);
		}

		if ($this->session->get(self::STATE) !== $state) {
			$this->logger->debug('state does not match');

			// TODO show page with forbidden
			return new JSONResponse([
				'got' => $state,
				'expected' => $this->session->get(self::STATE),
			], Http::STATUS_FORBIDDEN);
		}

		$providerId = (int)$this->session->get(self::PROVIDERID);
		$provider = $this->providerMapper->getProvider($providerId);

		$discovery = $this->discoveryService->obtainDiscovery($provider);

		$this->logger->debug('Obtainting data from: ' . $discovery['token_endpoint']);

		$client = $this->clientService->newClient();
		$result = $client->post(
			$discovery['token_endpoint'],
			[
				'body' => [
					'code' => $code,
					'client_id' => $provider->getClientId(),
					'client_secret' => $provider->getClientSecret(),
					'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
					'grant_type' => 'authorization_code',
				],
			]
		);

		$data = json_decode($result->getBody(), true);
		$this->logger->debug('Received code response: ' . json_encode($data, JSON_THROW_ON_ERROR));
		$this->eventDispatcher->dispatchTyped(new TokenObtainedEvent($data, $provider, $discovery));

		// TODO: proper error handling
		$idTokenRaw = $data['id_token'];
		$jwks = $this->discoveryService->obtainJWK($provider);
		JWT::$leeway = 60;
		$idTokenPayload = JWT::decode($idTokenRaw, $jwks, array_keys(JWT::$supported_algs));

		$this->logger->debug('Parsed the JWT payload: ' . json_encode($idTokenPayload, JSON_THROW_ON_ERROR));

		if ($idTokenPayload->exp < $this->timeFactory->getTime()) {
			$this->logger->debug('Token expired');
			// TODO: error properly
			return new JSONResponse(['token expired']);
		}

		// Verify audience
		if (!(($idTokenPayload->aud === $provider->getClientId() || in_array($provider->getClientId(), $idTokenPayload->aud, true)))) {
			$this->logger->debug('This token is not for us');
			// TODO: error properly
			return new JSONResponse(['audience does not match']);
		}

		if (isset($idTokenPayload->nonce) && $idTokenPayload->nonce !== $this->session->get(self::NONCE)) {
			$this->logger->debug('Nonce does not match');
			// TODO: error properly
			return new JSONResponse(['invalid nonce']);
		}

		// get user ID attribute
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');
		$userId = $idTokenPayload->{$uidAttribute} ?? null;
		if ($userId === null) {
			return new JSONResponse(['Failed to provision user']);
		}

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$autoProvisionAllowed = (!isset($oidcSystemConfig['auto_provision']) || $oidcSystemConfig['auto_provision']);

		// Provisioning
		if ($autoProvisionAllowed) {
			$user = $this->provisioningService->provisionUser($userId, $providerId, $idTokenPayload);
		} else {
			// in case user is provisioned by user_ldap, userManager->search() triggers an ldap search which syncs the results
			// so new users will be directly available even if they were not synced before this login attempt
			$this->userManager->search($userId);
			// when auto provision is disabled, we assume the user has been created by another user backend (or manually)
			$user = $this->userManager->get($userId);
			if ($this->ldapService->isLdapDeletedUser($user)) {
				$user = null;
			}
		}

		if ($user === null) {
			return new JSONResponse(['Failed to provision user']);
		}

		$this->session->set(self::ID_TOKEN, $idTokenRaw);

		$this->logger->debug('Logging user in');

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());
		$this->userSession->createRememberMeToken($user);

		// for backchannel logout
		try {
			$authToken = $this->authTokenProvider->getToken($this->session->getId());
			$this->sessionMapper->createSession(
				$idTokenPayload->sid ?? 'fallback-sid',
				$idTokenPayload->sub ?? 'fallback-sub',
				$idTokenPayload->iss ?? 'fallback-iss',
				$authToken->getId(),
				$this->session->getId()
			);
		} catch (InvalidTokenException $e) {
			$this->logger->debug('Auth token not found after login');
		}

		// if the user was provisioned by user_ldap, this is required to update and/or generate the avatar
		if ($user->canChangeAvatar()) {
			$this->logger->debug('$user->canChangeAvatar() is true');
		}

		$this->logger->debug('Redirecting user');

		$redirectUrl = $this->session->get(self::REDIRECT_AFTER_LOGIN);
		if ($redirectUrl) {
			return new RedirectResponse($redirectUrl);
		}

		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}

	/**
	 * Endpoint called by NC to logout in the IdP before killing the current session
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @return RedirectResponse
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \JsonException
	 * @throws Exception
	 * @throws SessionNotAvailableException
	 */
	public function singleLogoutService() {
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$targetUrl = $this->urlGenerator->getAbsoluteURL('/');
		if (!isset($oidcSystemConfig['single_logout']) || $oidcSystemConfig['single_logout']) {
			$providerId = $this->session->get(self::PROVIDERID);
			if ($providerId) {
				$provider = $this->providerMapper->getProvider((int)$providerId);
				$endSessionEndpoint = $this->discoveryService->obtainDiscovery($provider)['end_session_endpoint'];
				if ($endSessionEndpoint) {
					$endSessionEndpoint .= '?post_logout_redirect_uri=' . $targetUrl;
					$endSessionEndpoint .= '&client_id=' . $provider->getClientId();
					$shouldSendIdToken = $this->providerService->getSetting(
						$provider->getId(),
						ProviderService::SETTING_SEND_ID_TOKEN_HINT, '0'
					) === '1';
					$idToken = $this->session->get(self::ID_TOKEN);
					if ($shouldSendIdToken && $idToken) {
						$endSessionEndpoint .= '&id_token_hint=' . $idToken;
					}
					$targetUrl = $endSessionEndpoint;
				}
			}
		}

		// cleanup related oidc session
		$this->sessionMapper->deleteFromNcSessionId($this->session->getId());

		$this->userSession->logout();

		// make sure we clear the session to avoid messing with Backend::isSessionActive
		$this->session->clear();
		return new RedirectResponse($targetUrl);
	}

	/**
	 * Endpoint called by the IdP (OP) when end_session_endpoint is called by another client
	 * The logout token contains the sid for which we know the sessionId
	 * which leads to the auth token that we can invalidate
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $providerIdentifier
	 * @param string $logout_token
	 * @return JSONResponse
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function backChannelLogout(string $providerIdentifier, string $logout_token = ''): JSONResponse {
		// get the provider
		$provider = $this->providerService->getProviderByIdentifier($providerIdentifier);
		if ($provider === null) {
			return $this->getBackchannelLogoutErrorResponse('provider not found', 'The provider was not found in Nextcloud');
		}

		// decrypt the logout token
		$jwks = $this->discoveryService->obtainJWK($provider);
		JWT::$leeway = 60;
		$logoutTokenPayload = JWT::decode($logout_token, $jwks, array_keys(JWT::$supported_algs));

		$this->logger->debug('Parsed the logout JWT payload: ' . json_encode($logoutTokenPayload, JSON_THROW_ON_ERROR));

		// check the audience
		if (!(($logoutTokenPayload->aud === $provider->getClientId() || in_array($provider->getClientId(), $logoutTokenPayload->aud, true)))) {
			return $this->getBackchannelLogoutErrorResponse('invalid audience', 'The audience of the logout token does not match the provider');
		}

		// check the event attr
		if (!isset($logoutTokenPayload->events->{'http://schemas.openid.net/event/backchannel-logout'})) {
			return $this->getBackchannelLogoutErrorResponse('invalid event', 'The backchannel-logout event was not found in the logout token');
		}

		// check the nonce attr
		if (isset($logoutTokenPayload->nonce)) {
			return $this->getBackchannelLogoutErrorResponse('invalid nonce', 'The logout token should not contain a nonce attribute');
		}

		// get the auth token ID associated with the logout token's sid attr
		$sid = $logoutTokenPayload->sid;
		try {
			$oidcSession = $this->sessionMapper->findSessionBySid($sid);
		} catch (DoesNotExistException $e) {
			return $this->getBackchannelLogoutErrorResponse('invalid SID', 'The sid of the logout token was not found');
		} catch (MultipleObjectsReturnedException $e) {
			return $this->getBackchannelLogoutErrorResponse('invalid SID', 'The sid of the logout token was found multiple times');
		}

		$sub = $logoutTokenPayload->sub;
		if ($oidcSession->getSub() !== $sub) {
			return $this->getBackchannelLogoutErrorResponse('invalid SUB', 'The sub does not match the one from the login ID token');
		}
		$iss = $logoutTokenPayload->iss;
		if ($oidcSession->getIss() !== $iss) {
			return $this->getBackchannelLogoutErrorResponse('invalid ISS', 'The iss does not match the one from the login ID token');
		}

		// i don't know why but the cast is necessary
		$authTokenId = (int)$oidcSession->getAuthtokenId();
		try {
			$authToken = $this->authTokenProvider->getTokenById($authTokenId);
			// we could also get the auth token by nc session ID
			// $authToken = $this->authTokenProvider->getToken($oidcSession->getNcSessionId());
			$userId = $authToken->getUID();
			$this->authTokenProvider->invalidateTokenById($userId, $authToken->getId());
		} catch (InvalidTokenException $e) {
			return $this->getBackchannelLogoutErrorResponse('nc session not found', 'The authentication session was not found in Nextcloud');
		}

		// cleanup
		$this->sessionMapper->delete($oidcSession);

		return new JSONResponse([], Http::STATUS_OK);
	}

	/**
	 * Generate an error response according to the OIDC standard
	 * Log the error
	 *
	 * @param string $error
	 * @param string $description
	 * @return JSONResponse
	 */
	private function getBackchannelLogoutErrorResponse(string $error, string $description): JSONResponse {
		$this->logger->debug('Backchannel logout error. ' . $error . ' ; ' . $description);
		return new JSONResponse(
			[
				'error' => $error,
				'error_description' => $description,
			],
			Http::STATUS_BAD_REQUEST,
		);
	}
}
