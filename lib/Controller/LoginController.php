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
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\ID4MEProvider;
use OCA\UserOIDC\Service\OIDCProvider;
use OCA\UserOIDC\Service\OIDCProviderService;
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

	/** @var OIDCProviderService */
	private $providerService;

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
	/**
	 * @var IUserSession
	 */
	private $userSession;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var ITimeFactory
	 */
	private $timeFactory;

	public function __construct(
		IRequest $request,
		OIDCProviderService $providerService,
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

		$this->providerService = $providerService;
		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->urlGenerator = $urlGenerator;
		$this->userMapper = $userMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function login(int $providerId) {
		$provider = $this->providerService->getProvider($providerId);

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$this->session->set(self::PROVIDERID, $providerId);
		$this->session->close();

		$data = [
			'client_id' => $provider->getClientId(),
			'response_type' => 'code',
			'scope' => $provider->getScope(),
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APPID . '.login.code'),
			'state' => $state,
			'nonce' => $nonce,
		];

		$url = $provider->getAuthEndpoint() . '?' . http_build_query($data);
		return new RedirectResponse($url);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function code($state = '', $code = '', $scope = '') {
		$params = $this->request->getParams();

		if ($this->session->get(self::STATE) !== $state) {
			// TODO show page with forbidden
			return new JSONResponse([
				'got' => $state,
				'expected' => $this->session->get(self::STATE),
			], Http::STATUS_FORBIDDEN);
		}

		$providerId = (int)$this->session->get(self::PROVIDERID);
		$provider = $this->providerService->getProvider($providerId);

		$client = $this->clientService->newClient();
		$result = $client->post(
			$provider->getTokenEndpoint(),
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

		// Decode header and token
		[$header, $payload, $signature] = explode('.', $data['id_token']);
		$plainHeaders = json_decode(base64_decode($header), true);
		$plainPayload = json_decode(base64_decode($payload), true);

		/** TODO: VALIATE SIGNATURE! */

		if ($plainPayload['exp'] < $this->timeFactory->getTime()) {
			// TODO: error properly
			return new JSONResponse(['token expired']);
		}

		// Verify audience
		if ($plainPayload['aud'] !== $provider->getClientId()) {
			// TODO: error properly
			return new JSONResponse(['audience does not match']);
		}

		if (isset($plainPayload['nonce'])) {
			if ($plainPayload['nonce'] !== $this->session->get(self::NONCE)) {
				// TODO: error properly
				return new JSONResponse(['inavlid nonce']);
			}
		}

		// Insert or update user
		$backendUser = $this->userMapper->getOrCreate($providerId, $plainPayload['sub']);
		$user = $this->userManager->get($backendUser->getUserId());

		// Update e-mail
		if (isset($plainPayload['email'])) {
			$user->setEMailAddress($plainPayload['email']);
		}

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());

		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}
}
