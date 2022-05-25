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

use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCA\UserOIDC\Event\TokenObtainedEvent;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class LoginController extends Controller {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	public const PROVIDERID = 'oidc.providerid';
	private const REDIRECT_AFTER_LOGIN = 'oidc.redirect';

	/** @var ISecureRandom */
	private $random;

	/** @var ISession */
	private $session;

	/** @var IClientService */
	private $clientService;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var UserMapper */
	private $userMapper;

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
	/**
	 * @var IConfig
	 */
	private $config;

	public function __construct(
		IRequest $request,
		ProviderMapper $providerMapper,
		ProviderService $providerService,
		DiscoveryService $discoveryService,
		ISecureRandom $random,
		ISession $session,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		UserMapper $userMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		ITimeFactory $timeFactory,
		IEventDispatcher $eventDispatcher,
		IConfig $config,
		ILogger $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->discoveryService = $discoveryService;
		$this->urlGenerator = $urlGenerator;
		$this->userMapper = $userMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->timeFactory = $timeFactory;
		$this->providerMapper = $providerMapper;
		$this->providerService = $providerService;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
		$this->config = $config;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function login(int $providerId, string $redirectUrl = null) {
		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse($redirectUrl);
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

		$claims = [
			// more details about requesting claims:
			// https://openid.net/specs/openid-connect-core-1_0.html#IndividualClaimsRequests
			'id_token' => [
				// ['essential' => true] means it's mandatory but it won't trigger an error if it's not there
				// null means we want it
				$emailAttribute => null,
				$displaynameAttribute => null,
				$quotaAttribute => null,
			],
			'userinfo' => [
				$emailAttribute => null,
				$displaynameAttribute => null,
				$quotaAttribute => null,
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
			$this->logger->error('Could not reach provider at URL ' . $provider->getDiscoveryEndpoint());
			$response = new Http\TemplateResponse('', 'error', [
				'errors' => [
					['error' => 'Could not the reach OpenID Connect provider.']
				]
			], Http\TemplateResponse::RENDER_AS_ERROR);
			$response->setStatus(404);
			return $response;
		}

		//TODO verify discovery

		$url = $discovery['authorization_endpoint'] . '?' . http_build_query($data);
		$this->logger->debug('Redirecting user to: ' . $url);

		// Workaround to avoid empty session on special conditions in Safari
		// https://github.com/nextcloud/user_oidc/pull/358
		if ($this->request->isUserAgent(['/Safari/']) && !$this->request->isUserAgent(['/Chrome/'])) {
			return new Http\DataDisplayResponse('<meta http-equiv="refresh" content="0; url=' . $url . '" />');
		}

		return new RedirectResponse($url);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function code($state = '', $code = '', $scope = '', $error = '', $error_description = '') {
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
		$jwks = $this->discoveryService->obtainJWK($provider);
		JWT::$leeway = 60;
		$idTokenPayload = JWT::decode($data['id_token'], $jwks, array_keys(JWT::$supported_algs));

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

		$user = $this->provisionUser($userId, $providerId, $idTokenPayload);
		if ($user === null) {
			return new JSONResponse(['Failed to provision user']);
		}

		$this->logger->debug('Logging user in');

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());
		$this->userSession->createRememberMeToken($user);

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

	private function provisionUser(string $userId, int $providerId, object $idTokenPayload): ?IUser {
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$autoProvisionAllowed = (!isset($oidcSystemConfig['auto_provision']) || $oidcSystemConfig['auto_provision']);
		if (!$autoProvisionAllowed) {
			// in case user is provisioned by user_ldap, userManager->search() triggers an ldap search which syncs the results
			// so new users will be directly available even if they were not synced before this login attempt
			$this->userManager->search($userId);
			// when auto provision is disabled, we assume the user has been created by another user backend (or manually)
			return $this->userManager->get($userId);
		}

		// now we try to auto-provision

		// get name/email/quota information from the token itself
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$email = $idTokenPayload->{$emailAttribute} ?? null;
		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$userName = $idTokenPayload->{$displaynameAttribute} ?? null;
		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$quota = $idTokenPayload->{$quotaAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_UID, $idTokenPayload, $userId);
		$this->eventDispatcher->dispatchTyped($event);

		$backendUser = $this->userMapper->getOrCreate($providerId, $event->getValue());
		$this->logger->debug('User obtained from the OIDC user backend: ' . $backendUser->getUserId());

		$user = $this->userManager->get($backendUser->getUserId());
		if ($user === null) {
			return $user;
		}

		// Update displayname
		if (isset($userName)) {
			$newDisplayName = mb_substr($userName, 0, 255);
			$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $idTokenPayload, $newDisplayName);
		} else {
			$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $idTokenPayload);
		}
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Displayname mapping event dispatched');
		if ($event->hasValue()) {
			$newDisplayName = $event->getValue();
			if ($newDisplayName != $backendUser->getDisplayName()) {
				$backendUser->setDisplayName($newDisplayName);
				$backendUser = $this->userMapper->update($backendUser);
			}
		}

		// Update e-mail
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_EMAIL, $idTokenPayload, $email);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Email mapping event dispatched');
		if ($event->hasValue()) {
			$user->setEMailAddress($event->getValue());
		}

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $idTokenPayload, $quota);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Quota mapping event dispatched');
		if ($event->hasValue()) {
			$user->setQuota($event->getValue());
		}

		return $user;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @return Http\RedirectResponse
	 * @throws Error
	 */
	public function singleLogoutService() {
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$targetUrl = $this->urlGenerator->getAbsoluteURL('/');
		if (!isset($oidcSystemConfig['single_logout']) || $oidcSystemConfig['single_logout']) {
			$providerId = (int)$this->session->get(self::PROVIDERID);
			$provider = $this->providerMapper->getProvider($providerId);
			$targetUrl = $this->discoveryService->obtainDiscovery($provider)['end_session_endpoint'] ?? $this->urlGenerator->getAbsoluteURL('/');
			if ($targetUrl) {
				$targetUrl .= '?post_logout_redirect_uri=' . $this->urlGenerator->getAbsoluteURL('/');
			}
		}
		$this->userSession->logout();
		// make sure we clear the session to avoid messing with Backend::isSessionActive
		$this->session->clear();
		return new RedirectResponse($targetUrl);
	}
}
