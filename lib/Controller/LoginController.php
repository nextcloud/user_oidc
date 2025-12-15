<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @noinspection AdditionOperationOnArraysInspection */

declare(strict_types=1);

namespace OCA\UserOIDC\Controller;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OC\Authentication\Token\IProvider;
use OC\User\Session as OC_UserSession;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\SessionMapper;
use OCA\UserOIDC\Event\TokenObtainedEvent;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Service\SettingsService;
use OCA\UserOIDC\Service\TokenService;
use OCA\UserOIDC\User\Backend;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IToken;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
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
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class LoginController extends BaseOidcController {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	public const PROVIDERID = 'oidc.providerid';
	public const REDIRECT_AFTER_LOGIN = 'oidc.redirect';
	private const ID_TOKEN = 'oidc.id_token';
	private const CODE_VERIFIER = 'oidc.code_verifier';

	public function __construct(
		IRequest $request,
		private ProviderMapper $providerMapper,
		private ProviderService $providerService,
		private DiscoveryService $discoveryService,
		private LdapService $ldapService,
		private SettingsService $settingsService,
		private ISecureRandom $random,
		private ISession $session,
		private HttpClientHelper $clientService,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IEventDispatcher $eventDispatcher,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IProvider $authTokenProvider,
		private SessionMapper $sessionMapper,
		private ProvisioningService $provisioningService,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private ICrypto $crypto,
		private TokenService $tokenService,
		private OidcService $oidcService,
	) {
		parent::__construct($request, $config, $l10n);
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
			'message' => $this->l10n->t('You must access Nextcloud with HTTPS to use OpenID Connect.'),
		];
		$throttleMetadata = ['reason' => 'insecure connection'];
		return $this->buildFailureTemplateResponse($params, Http::STATUS_NOT_FOUND, $throttleMetadata, $throttle);
	}

	/**
	 * @param string|null $redirectUrl
	 * @return RedirectResponse
	 */
	private function getRedirectResponse(?string $redirectUrl = null): RedirectResponse {
		if ($redirectUrl === null) {
			return new RedirectResponse($this->urlGenerator->getBaseUrl());
		}

		// Remove protocol and domain name
		$filtered = preg_replace('/^https?:\/\/[^\/]+/', '', $redirectUrl);

		// Additional check: ensure the result starts with a single /
		if (!preg_match('/^\/[^\/]/', $filtered)) {
			return new RedirectResponse($this->urlGenerator->getBaseUrl());
		}

		return new RedirectResponse($filtered);
	}

	/**
	 * @param int $providerId
	 * @param string|null $redirectUrl
	 * @return DataDisplayResponse|RedirectResponse|TemplateResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[BruteForceProtection(action: 'userOidcLogin')]
	public function login(int $providerId, ?string $redirectUrl = null) {
		if ($this->userSession->isLoggedIn()) {
			return $this->getRedirectResponse($redirectUrl);
		}
		if (!$this->isSecure()) {
			return $this->buildProtocolErrorResponse();
		}
		$this->logger->debug('Initiating login for provider with id: ' . strval($providerId));

		try {
			$provider = $this->providerMapper->getProvider($providerId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			$message = $this->l10n->t('There is no such OpenID Connect provider.');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_NOT_FOUND, ['provider_not_found' => $providerId]);
		}

		// pass discovery query parameters also on to the authentication
		$data = [];
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

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);
		$this->session->set(self::REDIRECT_AFTER_LOGIN, $redirectUrl);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$isPkceSupported = in_array('S256', $discovery['code_challenge_methods_supported'] ?? [], true);
		$isPkceEnabled = $isPkceSupported && ($oidcSystemConfig['use_pkce'] ?? true);

		if ($isPkceEnabled) {
			// PKCE code_challenge see https://datatracker.ietf.org/doc/html/rfc7636
			$code_verifier = $this->random->generate(128, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER);
			$this->session->set(self::CODE_VERIFIER, $code_verifier);
		}

		$this->session->set(self::PROVIDERID, $providerId);
		$this->session->close();

		// get attribute mapping settings
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');

		$claims = [
			// more details about requesting claims:
			// https://openid.net/specs/openid-connect-core-1_0.html#IndividualClaimsRequests
			// ['essential' => true] means it's mandatory but it won't trigger an error if it's not there
			// null means we want it
			'id_token' => new \stdClass(),
			'userinfo' => new \stdClass(),
		];

		$resolveNestedClaims = $this->providerService->getSetting($providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0') === '1';
		// by default: default claims are ENABLED
		// default claims are historically for quota, email, displayName and groups
		$isDefaultClaimsEnabled = !isset($oidcSystemConfig['enable_default_claims']) || $oidcSystemConfig['enable_default_claims'] !== false;
		if ($isDefaultClaimsEnabled) {
			// default claims for quota, email, displayName and groups is ENABLED
			$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
			$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
			$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
			$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
			foreach ([$emailAttribute, $displaynameAttribute, $quotaAttribute, $groupsAttribute] as $claim) {
				$claims['id_token']->{$claim} = null;
				$claims['userinfo']->{$claim} = null;
			}
		} else {
			// No default claim, we only set the claims if an attribute is mapped
			$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL);
			$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME);
			$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA);
			$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS);
			$rawClaims = [$emailAttribute, $displaynameAttribute, $quotaAttribute, $groupsAttribute];

			if ($resolveNestedClaims) {
				$claimSet = [];
				foreach ($rawClaims as $claim) {
					if ($claim !== '') {
						$first = trim(explode('|', $claim)[0]);
						$claimSet[$first] = true;
					}
				}
				$rawClaims = array_keys($claimSet);
			}

			foreach ($rawClaims as $claim) {
				if ($claim !== '') {
					$claims['id_token']->{$claim} = null;
					$claims['userinfo']->{$claim} = null;
				}
			}
		}

		if ($uidAttribute !== 'sub') {
			$uidAttributeToRequest = $uidAttribute;
			if ($resolveNestedClaims) {
				$uidAttributeToRequest = trim(explode('|', $uidAttribute)[0]);
			}
			$claims['id_token']->{$uidAttributeToRequest} = ['essential' => true];
			$claims['userinfo']->{$uidAttributeToRequest} = ['essential' => true];
		}

		$extraClaimsString = $this->providerService->getSetting($providerId, ProviderService::SETTING_EXTRA_CLAIMS, '');
		if ($extraClaimsString) {
			$extraClaims = explode(' ', $extraClaimsString);
			foreach ($extraClaims as $extraClaim) {
				$claims['id_token']->{$extraClaim} = null;
				$claims['userinfo']->{$extraClaim} = null;
			}
		}

		$oidcConfig = $this->config->getSystemValue('user_oidc', []);

		$data += [
			'client_id' => $provider->getClientId(),
			'response_type' => 'code',
			'scope' => trim($provider->getScope()),
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
			'claims' => json_encode($claims),
			'state' => $state,
			'nonce' => $nonce,
		];

		if (isset($oidcConfig['prompt']) && is_string($oidcConfig['prompt'])) {
			$data['prompt'] = $oidcConfig['prompt'];
		}

		if ($isPkceEnabled) {
			$data['code_challenge'] = $this->toCodeChallenge($code_verifier);
			$data['code_challenge_method'] = 'S256';
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
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[BruteForceProtection(action: 'userOidcCode')]
	public function code(string $state = '', string $code = '', string $scope = '', string $error = '', string $error_description = '') {
		if (!$this->isSecure()) {
			return $this->buildProtocolErrorResponse();
		}
		$this->logger->debug('Code login with core: ' . $code . ' and state: ' . $state);

		if ($error !== '') {
			$this->logger->warning('Code login error', ['error' => $error, 'error_description' => $error_description]);
			if ($this->isDebugModeEnabled()) {
				return new JSONResponse([
					'error' => $error,
					'error_description' => $error_description,
				], Http::STATUS_FORBIDDEN);
			}
			$message = $this->l10n->t('The identity provider failed to authenticate the user.');
			return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, [], false);
		}

		$storedState = $this->session->get(self::STATE);

		if ($storedState !== $state) {
			$this->logger->warning('state does not match', [
				'got' => $state,
				'expected' => $storedState,
				'state_exists_in_session' => $this->session->exists(self::STATE),
			]);

			$message = $this->l10n->t('The received state does not match the expected value.');
			if ($this->isDebugModeEnabled()) {
				$responseData = [
					'error' => 'invalid_state',
					'error_description' => $message,
					'got' => $state,
					'expected' => $storedState,
					'state_exists_in_session' => $this->session->exists(self::STATE),
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

		$discovery = $this->discoveryService->obtainDiscovery($provider);

		$this->logger->debug('Obtainting data from: ' . $discovery['token_endpoint']);

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$isPkceSupported = in_array('S256', $discovery['code_challenge_methods_supported'] ?? [], true);
		$isPkceEnabled = $isPkceSupported && ($oidcSystemConfig['use_pkce'] ?? true);

		try {
			$requestBody = [
				'code' => $code,
				'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
				'grant_type' => 'authorization_code',
			];
			if ($isPkceEnabled) {
				$requestBody['code_verifier'] = $this->session->get(self::CODE_VERIFIER); // Set for the PKCE flow
			}

			$headers = [];
			// follow what is described in https://openid.net/specs/openid-connect-discovery-1_0.html
			// about token_endpoint_auth_methods_supported: "If omitted, the default is client_secret_basic"
			// Use client_secret_post if supported
			// We still allow changing the default auth method in config.php
			$tokenEndpointAuthMethod = $oidcSystemConfig['default_token_endpoint_auth_method'] ?? 'client_secret_basic';
			// deal with invalid values
			if (!in_array($tokenEndpointAuthMethod, ['client_secret_basic', 'client_secret_post'], true)) {
				$tokenEndpointAuthMethod = 'client_secret_basic';
			}
			if (
				array_key_exists('token_endpoint_auth_methods_supported', $discovery)
				&& is_array($discovery['token_endpoint_auth_methods_supported'])
				&& in_array('client_secret_post', $discovery['token_endpoint_auth_methods_supported'], true)
			) {
				$tokenEndpointAuthMethod = 'client_secret_post';
			}

			if ($tokenEndpointAuthMethod === 'client_secret_basic') {
				$headers = [
					'Authorization' => 'Basic ' . base64_encode($provider->getClientId() . ':' . $providerClientSecret),
					'Content-Type' => 'application/x-www-form-urlencoded',
				];
			} else {
				// Assuming client_secret_post as no other option is supported currently
				$requestBody['client_id'] = $provider->getClientId();
				$requestBody['client_secret'] = $providerClientSecret;
			}

			$body = $this->clientService->post(
				$discovery['token_endpoint'],
				$requestBody,
				$headers
			);
		} catch (ClientException|ServerException $e) {
			$response = $e->getResponse();
			$body = (string)$response->getBody();
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

		$data = json_decode($body, true);
		$this->logger->debug('Received code response: ' . json_encode($data, JSON_THROW_ON_ERROR));
		$this->eventDispatcher->dispatchTyped(new TokenObtainedEvent($data, $provider, $discovery));

		// TODO: proper error handling
		$idTokenRaw = $data['id_token'];
		$jwks = $this->discoveryService->obtainJWK($provider, $idTokenRaw);
		JWT::$leeway = 60;
		try {
			$idTokenPayload = JWT::decode($idTokenRaw, $jwks);
		} catch (UnexpectedValueException $e) {
			$this->logger->debug('Failed to decode the JWT token, retrying with fresh JWK');
			$jwks = $this->discoveryService->obtainJWK($provider, $idTokenRaw, false);
			$idTokenPayload = JWT::decode($idTokenRaw, $jwks);
		}

		// default is false
		if (isset($oidcSystemConfig['enrich_login_id_token_with_userinfo']) && $oidcSystemConfig['enrich_login_id_token_with_userinfo']) {
			$userInfo = $this->oidcService->userInfo($provider, $data['access_token']);
			foreach ($userInfo as $key => $value) {
				// give priority to id token values, only use userinfo ones if missing in id token
				if (!isset($idTokenPayload->{$key})) {
					$idTokenPayload->{$key} = $value;
				}
			}
		}

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
		$checkAudience = !isset($oidcSystemConfig['login_validation_audience_check'])
			|| !in_array($oidcSystemConfig['login_validation_audience_check'], [false, 'false', 0, '0'], true);
		if ($checkAudience) {
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
		}

		$checkAzp = !isset($oidcSystemConfig['login_validation_azp_check'])
			|| !in_array($oidcSystemConfig['login_validation_azp_check'], [false, 'false', 0, '0'], true);
		if ($checkAzp) {
			// ref https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
			// If the azp claim is present, it should be the client ID
			if (isset($idTokenPayload->azp) && $idTokenPayload->azp !== $provider->getClientId()) {
				$this->logger->debug('This token is not for us, authorized party (azp) is different than the client ID');
				$message = $this->l10n->t('The authorized party does not match ours');
				return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['invalid_azp' => $idTokenPayload->azp]);
			}
		}

		if (isset($idTokenPayload->nonce) && $idTokenPayload->nonce !== $this->session->get(self::NONCE)) {
			$this->logger->debug('Nonce does not match');
			$message = $this->l10n->t('The nonce does not match');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'invalid nonce']);
		}

		// get user ID attribute
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');
		$userId = $this->provisioningService->getClaimValue($idTokenPayload, $uidAttribute, $providerId);

		if ($userId === null) {
			$message = $this->l10n->t('Failed to provision the user');
			return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, ['reason' => 'failed to provision user']);
		}

		// prevent login of users that are not in a whitelisted group (if activated)
		$restrictLoginToGroups = $this->providerService->getSetting($providerId, ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS, '0');
		if ($restrictLoginToGroups === '1') {
			$syncGroups = $this->provisioningService->getSyncGroupsOfToken($providerId, $idTokenPayload);

			if ($syncGroups === null || count($syncGroups) === 0) {
				$this->logger->debug('Prevented user from login as user is not part of a whitelisted group');
				$message = $this->l10n->t('You do not have permission to log in to this instance. If you think this is an error, please contact an administrator.');
				return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'user not in any whitelisted group']);
			}
		}

		$autoProvisionAllowed = (!isset($oidcSystemConfig['auto_provision']) || $oidcSystemConfig['auto_provision']);
		$softAutoProvisionAllowed = (!isset($oidcSystemConfig['soft_auto_provision']) || $oidcSystemConfig['soft_auto_provision']);

		$shouldDoUserLookup = !$autoProvisionAllowed || ($softAutoProvisionAllowed && !$this->provisioningService->hasOidcUserProvisitioned($userId));
		if ($shouldDoUserLookup && $this->ldapService->isLDAPEnabled()) {
			// in case user is provisioned by user_ldap, userManager->search() triggers an ldap search which syncs the results
			// so new users will be directly available even if they were not synced before this login attempt
			$this->userManager->search($userId, 1, 0);
			$this->ldapService->syncUser($userId);
		}

		$existingUser = $this->userManager->get($userId);
		if ($existingUser !== null && $this->ldapService->isLdapDeletedUser($existingUser)) {
			$existingUser = null;
		}

		if ($autoProvisionAllowed) {
			if (!$softAutoProvisionAllowed && $existingUser !== null && $existingUser->getBackendClassName() !== Application::APP_ID) {
				// if soft auto-provisioning is disabled,
				// we refuse login for a user that already exists in another backend
				$message = $this->l10n->t('User conflict');
				return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, ['reason' => 'non-soft auto provision, user conflict'], false);
			}
			// use potential user from other backend, create it in our backend if it does not exist
			$provisioningResult = $this->provisioningService->provisionUser($userId, $providerId, $idTokenPayload, $existingUser);
			$user = $provisioningResult['user'];
			$this->session->set('user_oidc.oidcUserData', $provisioningResult['userData']);
		} else {
			// when auto provision is disabled, we assume the user has been created by another user backend (or manually)
			$user = $existingUser;
		}

		if ($user === null) {
			$message = $this->l10n->t('Failed to provision the user');
			return $this->build403TemplateResponse($message, Http::STATUS_BAD_REQUEST, ['reason' => 'failed to provision user']);
		}

		$this->session->set(self::ID_TOKEN, $idTokenRaw);

		$this->logger->debug('Logging user in');

		$this->userSession->setUser($user);
		if ($this->userSession instanceof OC_UserSession) {
			// TODO server should/could be refactored so we don't need to manually create the user session and dispatch the login-related events
			// Warning! If GSS is used, it reacts to the BeforeUserLoggedInEvent and handles the redirection itself
			// So nothing after dispatching this event will be executed
			$this->eventDispatcher->dispatchTyped(new BeforeUserLoggedInEvent($user->getUID(), null, \OCP\Server::get(Backend::class)));

			$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
			$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());
			$this->userSession->createRememberMeToken($user);

			// prevent password confirmation
			if (defined(IToken::class . '::SCOPE_SKIP_PASSWORD_VALIDATION')) {
				$token = $this->authTokenProvider->getToken($this->session->getId());
				$scope = $token->getScopeAsArray();
				$scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
				$token->setScope($scope);
				$this->authTokenProvider->updateToken($token);
			}

			$this->eventDispatcher->dispatchTyped(new UserLoggedInEvent($user, $user->getUID(), null, false));
		}

		$storeLoginTokenEnabled = $this->appConfig->getValueString(Application::APP_ID, 'store_login_token', '0', lazy: true) === '1';
		if ($storeLoginTokenEnabled) {
			// store all token information for potential token exchange requests
			$tokenData = array_merge(
				$data,
				['provider_id' => $providerId],
			);
			$this->tokenService->storeToken($tokenData);
		}
		$this->config->setUserValue($user->getUID(), Application::APP_ID, 'had_token_once', '1');

		// Set last password confirm to the future as we don't have passwords to confirm against with SSO
		$this->session->set('last-password-confirm', strtotime('+4 year', time()));

		// for backchannel logout
		try {
			$authToken = $this->authTokenProvider->getToken($this->session->getId());
			$this->sessionMapper->createOrUpdateSession(
				$idTokenPayload->sid ?? 'fallback-sid',
				$idTokenPayload->sub ?? 'fallback-sub',
				$idTokenPayload->iss ?? 'fallback-iss',
				$authToken->getId(),
				$this->session->getId(),
				$idTokenRaw,
				$user->getUID(),
				$providerId,
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
			return $this->getRedirectResponse($redirectUrl);
		}

		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}

	/**
	 * Endpoint called by NC to logout in the IdP before killing the current session
	 *
	 * @return RedirectResponse|TemplateResponse
	 * @throws Exception
	 * @throws SessionNotAvailableException
	 * @throws \JsonException
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[BruteForceProtection(action: 'userOidcSingleLogout')]
	public function singleLogoutService() {
		// TODO throttle in all failing cases
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$targetUrl = $this->urlGenerator->getAbsoluteURL('/');
		if (!isset($oidcSystemConfig['single_logout']) || $oidcSystemConfig['single_logout']) {
			$isFromGS = ($this->config->getSystemValueBool('gs.enabled', false)
				&& $this->config->getSystemValueString('gss.mode', '') === 'master');
			if ($isFromGS) {
				// Request is from master GlobalScale: we get the provider ID from the JWT token provided by the slave
				$jwt = $this->request->getParam('jwt', '');

				try {
					$key = $this->config->getSystemValueString('gss.jwt.key', '');
					$decoded = (array)JWT::decode($jwt, new Key($key, 'HS256'));

					$providerId = $decoded['oidcProviderId'] ?? null;
				} catch (\Exception $e) {
					$this->logger->debug('Failed to get the logout provider ID in the request from GSS', ['exception' => $e]);
				}
			} else {
				$providerId = $this->session->get(self::PROVIDERID);
				// if the provider is not found and we are in SSO mode, just use the one and only provider
				if ($providerId === null && !$this->settingsService->getAllowMultipleUserBackEnds()) {
					$providers = $this->providerMapper->getProviders();
					if (count($providers) === 1) {
						$providerId = $providers[0]->getId();
					}
				}
			}
			if ($providerId) {
				try {
					$provider = $this->providerMapper->getProvider((int)$providerId);
				} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
					$message = $this->l10n->t('There is no such OpenID Connect provider.');
					return $this->buildErrorTemplateResponse($message, Http::STATUS_NOT_FOUND, ['provider_id' => $providerId]);
				}

				// Check if a custom end_session_endpoint is set in the provider otherwise use the default one provided by the openid-configuration
				$discoveryData = $this->discoveryService->obtainDiscovery($provider);
				$defaultEndSessionEndpoint = $discoveryData['end_session_endpoint'] ?? null;
				$customEndSessionEndpoint = $provider->getEndSessionEndpoint();
				$endSessionEndpoint = $customEndSessionEndpoint ?: $defaultEndSessionEndpoint;

				if ($endSessionEndpoint) {
					$targetUrl = $provider->getPostLogoutUri() ?: $this->urlGenerator->getAbsoluteURL('/');
					$endSessionEndpoint .= '?post_logout_redirect_uri=' . $targetUrl;
					$endSessionEndpoint .= '&client_id=' . $provider->getClientId();
					$shouldSendIdToken = $this->providerService->getSetting(
						$provider->getId(),
						ProviderService::SETTING_SEND_ID_TOKEN_HINT,
						'0'
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
	 * @param string $providerIdentifier
	 * @param string $logout_token
	 * @return JSONResponse
	 * @throws Exception
	 * @throws \JsonException
	 */
	#[PublicPage]
	#[NoCSRFRequired]
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

		if (!isset($logoutTokenPayload->iss)) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid iss',
				'The logout token should contain an iss attribute',
				['iss_should_be_set' => true]
			);
		}
		$iss = $logoutTokenPayload->iss;

		if (!isset($logoutTokenPayload->sid) && !isset($logoutTokenPayload->sub)) {
			return $this->getBackchannelLogoutErrorResponse(
				'invalid sid+sub',
				'The logout token should contain sid or sub or both',
				['no_sid_no_sub' => true]
			);
		}

		$oidcSessionsToKill = [];

		// if SID is set, we look for this specific session (with or without using the sub, depending on if the sub is set)
		if (isset($logoutTokenPayload->sid)) {
			$sid = $logoutTokenPayload->sid;
			$sub = $logoutTokenPayload->sub ?? null;
			try {
				$oidcSession = $this->sessionMapper->findSessionBySid($sid, $sub, $iss);
			} catch (DoesNotExistException $e) {
				return $this->getBackchannelLogoutErrorResponse(
					$sub === null ? 'invalid SID or ISS' : 'invalid SID, SUB or ISS',
					$sub === null ? 'No session was found for this (sid,iss)' : 'No session was found for this (sid,sub,iss)',
					['session_not_found' => $sid]
				);
			} catch (MultipleObjectsReturnedException $e) {
				return $this->getBackchannelLogoutErrorResponse(
					$sub === null ? 'invalid SID or ISS' : 'invalid SID, SUB or ISS',
					$sub === null ? 'Multiple sessions were found with this (sid,iss)' : 'Multiple sessions were found with this (sid,sub,iss)',
					['multiple_sessions_found' => $sid]
				);
			}
			$oidcSessionsToKill[] = $oidcSession;
		} else {
			// here we know the sid is not set so the sub is set
			$sub = $logoutTokenPayload->sub;
			try {
				$oidcSessionsToKill = $this->sessionMapper->findSessionsBySubAndIss($sub, $iss);
			} catch (\OCP\Db\Exception $e) {
				return $this->getBackchannelLogoutErrorResponse(
					'error with sub+iss',
					'Failed to retrieve session with sub+iss',
					['sub_iss_error' => true]
				);
			}

			if (empty($oidcSessionsToKill)) {
				return $this->getBackchannelLogoutErrorResponse(
					'nothing found with sub+iss',
					'No session found with sub+iss',
					['sub_iss_no_session_found' => true]
				);
			}
		}

		foreach ($oidcSessionsToKill as $oidcSession) {
			// we know the IdP session is closed
			// we need this to prevent requesting the end_session_endpoint when we catch the TokenInvalidatedEvent
			$oidcSession->setIdpSessionClosed(1);
			$this->sessionMapper->update($oidcSession);

			$authTokenId = $oidcSession->getAuthtokenId();
			try {
				$authToken = $this->authTokenProvider->getTokenById($authTokenId);
				// we could also get the auth token by nc session ID
				// $authToken = $this->authTokenProvider->getToken($oidcSession->getNcSessionId());
				$userId = $authToken->getUID();
				$this->authTokenProvider->invalidateTokenById($userId, $authToken->getId());
			} catch (InvalidTokenException $e) {
				$this->logger->warning('[BackchannelLogout] Nextcloud session not found', ['authtoken_id' => $authTokenId]);
			}

			// cleanup
			$this->sessionMapper->delete($oidcSession);
		}

		return new JSONResponse([], Http::STATUS_OK);
	}

	/**
	 * Generate an error response according to the OIDC standard
	 * Log the error
	 *
	 * @param string $error
	 * @param string $description
	 * @param array $throttleMetadata
	 * @return JSONResponse
	 */
	private function getBackchannelLogoutErrorResponse(
		string $error,
		string $description,
		array $throttleMetadata = [],
	): JSONResponse {
		$this->logger->debug('Backchannel logout error. ' . $error . ' ; ' . $description);
		return new JSONResponse(
			[
				'error' => $error,
				'error_description' => $description,
			],
			Http::STATUS_BAD_REQUEST,
		);
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
