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

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\User\Session as OC_UserSession;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\SessionMapper;
use OCA\UserOIDC\Event\TokenObtainedEvent;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
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
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Psr\Log\LoggerInterface;

class LoginController extends BaseOidcController {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	public const PROVIDERID = 'oidc.providerid';
	private const REDIRECT_AFTER_LOGIN = 'oidc.redirect';
	private const ID_TOKEN = 'oidc.id_token';
	private const CODE_VERIFIER = 'oidc.code_verifier';

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

	/** @var LoggerInterface */
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

	/** @var IL10N */
	private $l10n;
	/**
	 * @var ICrypto
	 */
	private $crypto;

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
		IL10N $l10n,
		LoggerInterface $logger,
		ICrypto $crypto
	) {
		parent::__construct($request, $config);

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
		$this->l10n = $l10n;
		$this->crypto = $crypto;
	}

	/**
	 * @return bool
	 */
	private function isSecure(): bool {
		// no restriction in debug mode
		return $this->isDebugModeEnabled() || $this->request->getServerProtocol() === 'https';
	}

	/**
	 * @param bool|null $throttle
	 * @return TemplateResponse
	 */
	private function buildProtocolErrorResponse(?bool $throttle = null): TemplateResponse {
		$params = [
			'errors' => [
				['error' => $this->l10n->t('You must access Nextcloud with HTTPS to use OpenID Connect.')],
			],
		];
		$throttleMetadata = ['reason' => 'insecure connection'];
		return $this->buildFailureTemplateResponse('', 'error', $params, Http::STATUS_NOT_FOUND, $throttleMetadata, $throttle);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 * @BruteForceProtection(action=userOidcLogin)
	 *
	 * @param int $providerId
	 * @param string|null $redirectUrl
	 * @return DataDisplayResponse|RedirectResponse|TemplateResponse
	 */
	public function login(int $providerId, string $redirectUrl = null) {
		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse($redirectUrl);
		}
		if (!$this->isSecure()) {
			return $this->buildProtocolErrorResponse();
		}
		$this->logger->debug('Initiating login for provider with id: ' . $providerId);

		try {
			$provider = $this->providerMapper->getProvider($providerId);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			$message = $this->l10n->t('There is not such OpenID Connect provider.');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_NOT_FOUND, ['provider_not_found' => $providerId]);
		}

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);
		$this->session->set(self::REDIRECT_AFTER_LOGIN, $redirectUrl);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$isPkceEnabled = isset($oidcSystemConfig['use_pkce']) && $oidcSystemConfig['use_pkce'];
		if ($isPkceEnabled) {
			// PKCE code_challenge see https://datatracker.ietf.org/doc/html/rfc7636
			$code_verifier = $this->random->generate(128, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER);
			$this->session->set(self::CODE_VERIFIER, $code_verifier);
		}

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
			'scope' => trim($provider->getScope()),
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
			'claims' => json_encode($claims),
			'state' => $state,
			'nonce' => $nonce,
		];
		if ($isPkceEnabled) {
			$data['code_challenge'] = $this->toCodeChallenge($code_verifier);
			$data['code_challenge_method'] = 'S256';
		}
		// pass discovery query parameters also on to the authentication
		$discoveryUrl = parse_url($provider->getDiscoveryEndpoint());
		if (isset($discoveryUrl['query'])) {
			$this->logger->debug('Add custom discovery query: ' . $discoveryUrl['query']);
			$discoveryQuery = [];
			parse_str($discoveryUrl['query'], $discoveryQuery);
			$data += $discoveryQuery;
		}

		try {
			$discovery = $this->discoveryService->obtainDiscovery($provider);
		} catch (\Exception $e) {
			$this->logger->error('Could not reach the provider at URL ' . $provider->getDiscoveryEndpoint(), ['exception' => $e]);
			$message = $this->l10n->t('Could not reach the OpenID Connect provider.');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_NOT_FOUND, ['reason' => 'provider unreachable']);
		}

		$authorizationUrl = $this->discoveryService->buildAuthorizationUrl($discovery['authorization_endpoint'], $data);

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
	 * @BruteForceProtection(action=userOidcCode)
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
			return $this->buildProtocolErrorResponse();
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

			$message = $this->l10n->t('The received state does not match the expected value.');
			if ($this->isDebugModeEnabled()) {
				$responseData = [
					'error' => 'invalid_state',
					'error_description' => $message,
					'got' => $state,
					'expected' => $this->session->get(self::STATE),
				];
				return new JSONResponse($responseData, Http::STATUS_FORBIDDEN);
			}
			// we know debug mode is off, always throttle
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'state does not match'], true);
		}

		$providerId = (int)$this->session->get(self::PROVIDERID);
		$provider = $this->providerMapper->getProvider($providerId);
		try {
			$providerClientSecret = $this->crypto->decrypt($provider->getClientSecret());
		} catch (\Exception $e) {
			$this->logger->error('Failed to decrypt the client secret', ['exception' => $e]);
			$message = $this->l10n->t('Failed to decrypt the OIDC provider client secret');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, [], false);
		}

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$isPkceEnabled = isset($oidcSystemConfig['use_pkce']) && $oidcSystemConfig['use_pkce'];

		$discovery = $this->discoveryService->obtainDiscovery($provider);

		$this->logger->debug('Obtainting data from: ' . $discovery['token_endpoint']);

		$client = $this->clientService->newClient();
		try {
			$requestBody = [
				'code' => $code,
				'client_id' => $provider->getClientId(),
				'client_secret' => $providerClientSecret,
				'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
				'grant_type' => 'authorization_code',
			];
			if ($isPkceEnabled) {
				$requestBody['code_verifier'] = $this->session->get(self::CODE_VERIFIER); // Set for the PKCE flow
			}
			$result = $client->post(
				$discovery['token_endpoint'],
				[
					'body' => $requestBody,
				]
			);
		} catch (ClientException | ServerException $e) {
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			$responseBodyArray = json_decode($body, true);
			if ($responseBodyArray !== null && isset($responseBodyArray['error'], $responseBodyArray['error_description'])) {
				$this->logger->debug('Failed to contact the OIDC provider token endpoint', [
					'exception' => $e,
					'error' => $responseBodyArray['error'],
					'error_description' => $responseBodyArray['error_description'],
				]);
				$message = $this->l10n->t('Failed to contact the OIDC provider token endpoint') . ': ' . $responseBodyArray['error_description'];
			} else {
				$this->logger->debug('Failed to contact the OIDC provider token endpoint', ['exception' => $e]);
				$message = $this->l10n->t('Failed to contact the OIDC provider token endpoint');
			}
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, [], false);
		} catch (\Exception $e) {
			$this->logger->debug('Failed to contact the OIDC provider token endpoint', ['exception' => $e]);
			$message = $this->l10n->t('Failed to contact the OIDC provider token endpoint');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, [], false);
		}

		$data = json_decode($result->getBody(), true);
		$this->logger->debug('Received code response: ' . json_encode($data, JSON_THROW_ON_ERROR));
		$this->eventDispatcher->dispatchTyped(new TokenObtainedEvent($data, $provider, $discovery));

		// TODO: proper error handling
		$idTokenRaw = $data['id_token'];
		$jwks = $this->discoveryService->obtainJWK($provider, $idTokenRaw);
		JWT::$leeway = 60;
		$idTokenPayload = JWT::decode($idTokenRaw, $jwks);

		$this->logger->debug('Parsed the JWT payload: ' . json_encode($idTokenPayload, JSON_THROW_ON_ERROR));

		if ($idTokenPayload->exp < $this->timeFactory->getTime()) {
			$this->logger->debug('Token expired');
			$message = $this->l10n->t('The received token is expired.');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'token expired']);
		}

		// Verify issuer
		if ($idTokenPayload->iss !== $discovery['issuer']) {
			$this->logger->debug('This token is issued by the wrong issuer');
			$message = $this->l10n->t('The issuer does not match the one from the discovery endpoint');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['invalid_issuer' => $idTokenPayload->iss]);
		}

		// Verify audience
		$tokenAudience = $idTokenPayload->aud;
		$providerClientId = $provider->getClientId();
		if (
			(is_string($tokenAudience) && $tokenAudience !== $providerClientId)
				|| (is_array($tokenAudience) && !in_array($providerClientId, $tokenAudience, true))
		) {
			$this->logger->debug('This token is not for us');
			$message = $this->l10n->t('The audience does not match ours');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['invalid_audience' => $idTokenPayload->aud]);
		}

		// If the ID Token contains multiple audiences, the Client SHOULD verify that an azp Claim is present.
		// If an azp (authorized party) Claim is present, the Client SHOULD verify that its client_id is the Claim Value.
		if (is_array($idTokenPayload->aud) && count($idTokenPayload->aud) > 1) {
			if (isset($idTokenPayload->azp)) {
				if ($idTokenPayload->azp !== $provider->getClientId()) {
					$this->logger->debug('This token is not for us, authorized party (azp) is different than the client ID');
					$message = $this->l10n->t('The authorized party does not match ours');
					return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['invalid_azp' => $idTokenPayload->azp]);
				}
			} else {
				$this->logger->debug('Multiple audiences but no authorized party (azp) in the id token');
				$message = $this->l10n->t('No authorized party');
				return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['missing_azp']);
			}
		}

		if (isset($idTokenPayload->nonce) && $idTokenPayload->nonce !== $this->session->get(self::NONCE)) {
			$this->logger->debug('Nonce does not match');
			$message = $this->l10n->t('The nonce does not match');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'invalid nonce']);
		}

		// get user ID attribute
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');
		$userId = $idTokenPayload->{$uidAttribute} ?? null;
		if ($userId === null) {
			$message = $this->l10n->t('Failed to provision the user');
			return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, ['reason' => 'failed to provision user']);
		}

		$autoProvisionAllowed = (!isset($oidcSystemConfig['auto_provision']) || $oidcSystemConfig['auto_provision']);

		// in case user is provisioned by user_ldap, userManager->search() triggers an ldap search which syncs the results
		// so new users will be directly available even if they were not synced before this login attempt
		$this->userManager->search($userId);
		$this->ldapService->syncUser($userId);
		$userFromOtherBackend = $this->userManager->get($userId);
		if ($userFromOtherBackend !== null && $this->ldapService->isLdapDeletedUser($userFromOtherBackend)) {
			$userFromOtherBackend = null;
		}

		if ($autoProvisionAllowed) {
			$softAutoProvisionAllowed = (!isset($oidcSystemConfig['soft_auto_provision']) || $oidcSystemConfig['soft_auto_provision']);
			if (!$softAutoProvisionAllowed && $userFromOtherBackend !== null) {
				// if soft auto-provisioning is disabled,
				// we refuse login for a user that already exists in another backend
				$message = $this->l10n->t('User conflict');
				return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, ['reason' => 'non-soft auto provision, user conflict'], false);
			}
			// use potential user from other backend, create it in our backend if it does not exist
			$user = $this->provisioningService->provisionUser($userId, $providerId, $idTokenPayload, $userFromOtherBackend);
		} else {
			// when auto provision is disabled, we assume the user has been created by another user backend (or manually)
			$user = $userFromOtherBackend;
		}

		if ($user === null) {
			$message = $this->l10n->t('Failed to provision the user');
			return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, ['reason' => 'failed to provision user']);
		}

		$this->session->set(self::ID_TOKEN, $idTokenRaw);

		$this->logger->debug('Logging user in');

		$this->userSession->setUser($user);
		if ($this->userSession instanceof OC_UserSession) {
			$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
			$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());
			$this->userSession->createRememberMeToken($user);
		}

		// Set last password confirm to the future as we don't have passwords to confirm against with SSO
		$this->session->set('last-password-confirm', strtotime('+4 year', time()));

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
	 * @BruteForceProtection(action=userOidcSingleLogout)
	 *
	 * @return RedirectResponse|TemplateResponse
	 * @throws Exception
	 * @throws SessionNotAvailableException
	 * @throws \JsonException
	 */
	public function singleLogoutService() {
		// TODO throttle in all failing cases
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$targetUrl = $this->urlGenerator->getAbsoluteURL('/');
		if (!isset($oidcSystemConfig['single_logout']) || $oidcSystemConfig['single_logout']) {
			$providerId = $this->session->get(self::PROVIDERID);
			if ($providerId) {
				try {
					$provider = $this->providerMapper->getProvider((int)$providerId);
				} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
					$message = $this->l10n->t('There is not such OpenID Connect provider.');
					return $this->buildErrorTemplateResponse($message, Http::STATUS_NOT_FOUND, ['provider_id' => $providerId]);
				}

				// Check if a custom end_session_endpoint is deposited otherwise use the default one provided by the openid-configuration
				$discoveryData = $this->discoveryService->obtainDiscovery($provider);
				$defaultEndSessionEndpoint = $discoveryData['end_session_endpoint'] ?? null;
				$customEndSessionEndpoint = $provider->getEndSessionEndpoint();
				$endSessionEndpoint = $customEndSessionEndpoint ?: $defaultEndSessionEndpoint;

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
	 * Implemented according to https://openid.net/specs/openid-connect-backchannel-1_0.html
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 * @BruteForceProtection(action=userOidcBackchannelLogout)
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
			return $this->getBackchannelLogoutErrorResponse(
				'provider not found',
				'The provider was not found in Nextcloud',
				['provider_not_found' => $providerIdentifier]
			);
		}

		// decrypt the logout token
		$jwks = $this->discoveryService->obtainJWK($provider, $logout_token);
		JWT::$leeway = 60;
		$logoutTokenPayload = JWT::decode($logout_token, $jwks);

		$this->logger->debug('Parsed the logout JWT payload: ' . json_encode($logoutTokenPayload, JSON_THROW_ON_ERROR));

		// check the audience
		if (!(($logoutTokenPayload->aud === $provider->getClientId() || in_array($provider->getClientId(), $logoutTokenPayload->aud, true)))) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid audience',
				'The audience of the logout token does not match the provider',
				['invalid_audience' => $logoutTokenPayload->aud]
			);
		}

		// check the event attr
		if (!isset($logoutTokenPayload->events->{'http://schemas.openid.net/event/backchannel-logout'})) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid event',
				'The backchannel-logout event was not found in the logout token',
				['invalid_event' => true]
			);
		}

		// check the nonce attr
		if (isset($logoutTokenPayload->nonce)) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid nonce',
				'The logout token should not contain a nonce attribute',
				['nonce_should_not_be_set' => true]
			);
		}

		// get the auth token ID associated with the logout token's sid attr
		$sid = $logoutTokenPayload->sid;
		try {
			$oidcSession = $this->sessionMapper->findSessionBySid($sid);
		} catch (DoesNotExistException $e) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid SID',
				'The sid of the logout token was not found',
				['session_sid_not_found' => $sid]
			);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid SID',
				'The sid of the logout token was found multiple times',
				['multiple_logout_tokens_found' => $sid]
			);
		}

		$sub = $logoutTokenPayload->sub;
		if ($oidcSession->getSub() !== $sub) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid SUB',
				'The sub does not match the one from the login ID token',
				['invalid_sub' => $sub]
			);
		}
		$iss = $logoutTokenPayload->iss;
		if ($oidcSession->getIss() !== $iss) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid ISS',
				'The iss does not match the one from the login ID token',
				['invalid_iss' => $iss]
			);
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
			return $this->getBackchannelLogoutErrorResponse(
				'nc session not found',
				'The authentication session was not found in Nextcloud',
				['nc_auth_session_not_found' => $authTokenId]
			);
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
	 * @param array $throttleMetadata
	 * @param bool|null $throttle
	 * @return JSONResponse
	 */
	private function getBackchannelLogoutErrorResponse(string $error, string $description,
		array $throttleMetadata = [], ?bool $throttle = null): JSONResponse {
		$this->logger->debug('Backchannel logout error. ' . $error . ' ; ' . $description);
		$response = new JSONResponse(
			[
				'error' => $error,
				'error_description' => $description,
			],
			Http::STATUS_BAD_REQUEST,
		);
		if (($throttle === null && !$this->isDebugModeEnabled()) || $throttle) {
			$response->throttle($throttleMetadata);
		}
		return $response;
	}

	private function toCodeChallenge(string $data): string {
		// Basically one big work around for the base64url decode being weird
		$h = pack('H*', hash('sha256', $data));
		$s = base64_encode($h); // Regular base64 encoder
		$s = explode('=', $s)[0]; // Remove any trailing '='s
		$s = str_replace('+', '-', $s); // 62nd char of encoding
		$s = str_replace('/', '_', $s); // 63rd char of encoding
		return $s;
	}
}
