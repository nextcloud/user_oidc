<?php

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

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Id4Me;
use OCA\UserOIDC\Db\Id4MeMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\ID4MeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class Id4meController extends Controller {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	private const AUTHNAME = 'oidc.authname';

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

	/** @var Id4MeMapper */
	private $id4MeMapper;

	/** @var Service */
	private $id4me;

	/** @var ID4MeService */
	private $id4MeService;

	/** @var IL10N */
	private $l10n;


	public function __construct(
		IRequest $request,
		ISecureRandom $random,
		ISession $session,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		UserMapper $userMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		HttpClientHelper $clientHelper,
		Id4MeMapper $id4MeMapper,
		ID4MeService $id4MeService,
		IL10N $l10n
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->urlGenerator = $urlGenerator;
		$this->userMapper = $userMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->id4me = new Service($clientHelper);
		$this->id4MeMapper = $id4MeMapper;
		$this->id4MeService = $id4MeService;
		$this->l10n = $l10n;
	}

	private function build403TemplateResponse(): Http\TemplateResponse {
		$response = new Http\TemplateResponse(
			'core',
			'403',
			['message' => $this->l10n->t('ID4Me is disabled')],
			Http\TemplateResponse::RENDER_AS_ERROR
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		return $response;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function showLogin() {
		if (!$this->id4MeService->getID4ME()) {
			return $this->build403TemplateResponse();
		}
		$response = new Http\TemplateResponse('user_oidc', 'id4me/login', [], 'guest');

		$csp = new Http\ContentSecurityPolicy();
		$csp->addAllowedFormActionDomain('*');

		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * @PublicPage
	 * @UseSession
	 */
	public function login(string $domain) {
		if (!$this->id4MeService->getID4ME()) {
			return $this->build403TemplateResponse();
		}
		$authorityName = $this->id4me->discover($domain);
		$openIdConfig = $this->id4me->getOpenIdConfig($authorityName);

		try {
			$id4Me = $this->id4MeMapper->findByIdentifier($authorityName);
		} catch (DoesNotExistException $e) {
			$id4Me = $this->registerClient($authorityName, $openIdConfig);
		}

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$this->session->set(self::AUTHNAME, $authorityName);
		$this->session->close();

		$data = [
			'client_id' => $id4Me->getClientId(),
			'response_type' => 'code',
			'scope' => 'openid email profile',
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.id4me.code'),
			'state' => $state,
			'nonce' => $nonce,
		];

		$url = $openIdConfig->getAuthorizationEndpoint() . '?' . http_build_query($data);
		return new RedirectResponse($url);
	}

	private function registerClient(string $authorityName, OpenIdConfig $openIdConfig): Id4Me {
		$client = $this->id4me->register($openIdConfig, 'Nextcloud test', $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.id4me.code'), 'native');

		$id4Me = new Id4Me();
		$id4Me->setIdentifier($authorityName);
		$id4Me->setClientId($client->getClientId());
		$id4Me->setClientSecret($client->getClientSecret());

		return $this->id4MeMapper->insert($id4Me);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function code($state = '', $code = '', $scope = '') {
		if (!$this->id4MeService->getID4ME()) {
			return $this->build403TemplateResponse();
		}
		$params = $this->request->getParams();

		if ($this->session->get(self::STATE) !== $state) {
			// TODO show page with forbidden
			return new JSONResponse([
				'got' => $state,
				'expected' => $this->session->get(self::STATE),
			], Http::STATUS_FORBIDDEN);
		}

		$authorityName = $this->session->get(self::AUTHNAME);
		$openIdConfig = $this->id4me->getOpenIdConfig($authorityName);

		$id4Me = $this->id4MeMapper->findByIdentifier($authorityName);

		$client = $this->clientService->newClient();
		$result = $client->post(
			$openIdConfig->getTokenEndpoint(),
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode($id4Me->getClientId() . ':' . $id4Me->getClientSecret())
				],
				'body' => [
					'code' => $code,
					'client_id' => $id4Me->getClientId(),
					'client_secret' => $id4Me->getClientSecret(),
					'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.id4me.code'),
					'grant_type' => 'authorization_code',
				],
			]
		);

		$data = json_decode($result->getBody(), true);

		// Decode header and token
		[$header, $payload, $signature] = explode('.', $data['id_token']);
		$plainHeaders = json_decode(base64_decode($header), true);
		$plainPayload = json_decode(base64_decode($payload), true);

		// validate the JWT signature
		$idTokenRaw = $data['id_token'];
		$jwkUri = $openIdConfig->getJwksUri();
		JWT::$leeway = 60;
		try {
			$jwks = $this->id4MeService->obtainJWK($jwkUri, $data['id_token'], true);
			$idTokenPayload = JWT::decode($idTokenRaw, $jwks);
		} catch (\Exception|\Throwable $e) {
			$this->logger->debug('Failed to decode the JWT token, retrying with fresh JWK');
			try {
				$jwks = $this->id4MeService->obtainJWK($jwkUri, $idTokenRaw, false);
				$idTokenPayload = JWT::decode($idTokenRaw, $jwks);
			} catch (\Exception|\Throwable $e) {
				$this->logger->debug('Failed to decode the JWT token with fresh JWK');
				$message = $this->l10n->t('Failed to authenticate');
				return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'token signature check failed']);
			}
		}

		// TODO: validate expiration

		// Verify audience
		if ($plainPayload['aud'] !== $id4Me->getClientId()) {
			// TODO: error properly
			return new JSONResponse(['audience does not match']);
		}

		// TODO: VALIDATE NONCE (if set)

		// Insert or update user
		$backendUser = $this->userMapper->getOrCreate($id4Me->getId(), $plainPayload['sub'], true);
		$user = $this->userManager->get($backendUser->getUserId());

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());

		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}
}
