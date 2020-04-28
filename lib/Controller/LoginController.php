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

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class LoginController extends Controller {

	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	private const PROVIDERID = 'oidc.providerid';

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

	public function __construct(
		IRequest $request,
		ProviderMapper $providerMapper,
		ISecureRandom $random,
		ISession $session,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		UserMapper $userMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		ITimeFactory $timeFactory
	) {
		parent::__construct(Application::APPID, $request);

		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->urlGenerator = $urlGenerator;
		$this->userMapper = $userMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->timeFactory = $timeFactory;
		$this->providerMapper = $providerMapper;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function login(int $providerId) {
		//TODO: handle exceptions
		$provider = $this->providerMapper->getProvider($providerId);

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$this->session->set(self::PROVIDERID, $providerId);
		$this->session->close();

		$data = [
			'client_id' => $provider->getClientId(),
			'response_type' => 'code',
			'scope' => 'openid email profile',
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APPID . '.login.code'),
			'state' => $state,
			'nonce' => $nonce,
		];

		$discovery = $this->obtainDiscovery($provider->getDiscoveryEndpoint());

		//TODO verify discovery

		$url = $discovery['authorization_endpoint'] . '?' . http_build_query($data);
		return new RedirectResponse($url);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function code($state = '', $code = '', $scope = '') {
		if ($this->session->get(self::STATE) !== $state) {
			// TODO show page with forbidden
			return new JSONResponse([
				'got' => $state,
				'expected' => $this->session->get(self::STATE),
			], Http::STATUS_FORBIDDEN);
		}

		$providerId = (int)$this->session->get(self::PROVIDERID);
		$provider = $this->providerMapper->getProvider($providerId);

		$discovery = $this->obtainDiscovery($provider->getDiscoveryEndpoint());

		$client = $this->clientService->newClient();
		$result = $client->post(
			$discovery['token_endpoint'],
			[
				'body' => [
					'code' => $code,
					'client_id' => $provider->getClientId(),
					'client_secret' => $provider->getClientSecret(),
					'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APPID . '.login.code'),
					'grant_type' => 'authorization_code',
				],
			]
		);

		$data = json_decode($result->getBody(), true);

		// Obtain jwks
		$client = $this->clientService->newClient();
		$result = json_decode($client->get($discovery['jwks_uri'])->getBody(), true);
		$jwks = JWK::parseKeySet($result);

		// TODO: proper error handling
		$payload = JWT::decode($data['id_token'], $jwks, array_keys(JWT::$supported_algs));

		if ($payload->exp < $this->timeFactory->getTime()) {
			// TODO: error properly
			return new JSONResponse(['token expired']);
		}

		// Verify audience
		if ($payload->aud !== $provider->getClientId()) {
			// TODO: error properly
			return new JSONResponse(['audience does not match']);
		}

		if (isset($payload->nonce) && $payload->nonce !== $this->session->get(self::NONCE)) {
				// TODO: error properly
				return new JSONResponse(['inavlid nonce']);
		}

		// Insert or update user
		$backendUser = $this->userMapper->getOrCreate($providerId, $payload->sub);

		// Update displayname
		if (isset($payload->name)) {
			$newDisplayName = mb_substr($payload->name, 0, 255);

			if ($newDisplayName != $backendUser->getDisplayName()) {
				$backendUser->setDisplayName($payload->name);
				$backendUser = $this->userMapper->update($backendUser);

				//TODO: dispatch event for the update
			}
		}

		$user = $this->userManager->get($backendUser->getUserId());

		// Update e-mail
		if (isset($payload->email)) {
			$user->setEMailAddress($payload->email);
		}

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());

		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}

	private function obtainDiscovery(string $url) {
		$client = $this->clientService->newClient();
		$response = $client->get($url);

		//TODO handle failures gracefull
		$body = json_decode($response->getBody(), true);
		return $body;
	}
}
