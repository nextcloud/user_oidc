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

namespace OCA\UserOIDC\User;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\IApacheBackend;
use OCP\DB\Exception;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\ISession;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordConfirmationBackend;
use Psr\Log\LoggerInterface;

class Backend extends ABackend implements IPasswordConfirmationBackend, IGetDisplayNameBackend, IApacheBackend {

	/** @var UserMapper */
	private $userMapper;
	/** @var LoggerInterface */
	private $logger;
	/**
	 * @var ISession
	 */
	private $session;
	/**
	 * @var IRequest
	 */
	private $request;
	/**
	 * @var IClientService
	 */
	private $clientService;
	/**
	 * @var ProviderMapper
	 */
	private $providerMapper;
	/**
	 * @var ITimeFactory
	 */
	private $timeFactory;
	/**
	 * @var ProviderService
	 */
	private $providerService;

	public function __construct(UserMapper $userMapper,
								LoggerInterface $logger,
								ISession $session,
								IRequest $request,
								ITimeFactory $timeFactory,
								IClientService $clientService,
								ProviderService $providerService,
								ProviderMapper $providerMapper) {
		$this->userMapper = $userMapper;
		$this->logger = $logger;
		$this->session = $session;
		$this->request = $request;
		$this->clientService = $clientService;
		$this->providerMapper = $providerMapper;
		$this->timeFactory = $timeFactory;
		$this->providerService = $providerService;
	}

	public function getBackendName(): string {
		return Application::APP_ID;
	}

	public function deleteUser($uid): bool {
		try {
			$user = $this->userMapper->getUser($uid);
			$this->userMapper->delete($user);
			return true;
		} catch (Exception $e) {
			$this->logger->error('Failed to delete user', [ 'exception' => $e ]);
			return false;
		}
	}

	public function getUsers($search = '', $limit = null, $offset = null) {
		return array_map(function ($user) {
			return $user->getUserId();
		}, $this->userMapper->find($search, $limit, $offset));
	}

	public function userExists($uid): bool {
		return $this->userMapper->userExists($uid);
	}

	public function getDisplayName($uid): string {
		try {
			$user = $this->userMapper->getUser($uid);
		} catch (DoesNotExistException $e) {
			return $uid;
		}

		return $user->getDisplayName();
	}

	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		return $this->userMapper->findDisplayNames($search, $limit, $offset);
	}

	public function hasUserListings(): bool {
		return true;
	}

	public function canConfirmPassword(string $uid): bool {
		return false;
	}

	/**
	 * In case the user has been authenticated by Apache true is returned.
	 *
	 * @return boolean whether Apache reports a user as currently logged in.
	 * @since 6.0.0
	 */
	public function isSessionActive() {
		// if this returns true, getCurrentUserId is called
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		return $headerToken !== '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLogoutUrl() {
		return '';
	}

	/**
	 * Return the id of the current user
	 * @return string
	 * @since 6.0.0
	 */
	public function getCurrentUserId() {
		// get the first provider
		// TODO make sure this fits our needs and there never is more than one provider
		$providers = $this->providerMapper->getProviders();
		if (count($providers) > 0) {
			$provider = $providers[0];
		} else {
			$this->logger->error('no OIDC providers');
			return '';
		}

		// get the JWKS from the provider
		$discovery = $this->obtainDiscovery($provider->getDiscoveryEndpoint());
		$client = $this->clientService->newClient();
		$result = json_decode($client->get($discovery['jwks_uri'])->getBody(), true);
		$this->logger->debug('Obtained the jwks');

		$jwks = JWK::parseKeySet($result);
		$this->logger->debug('Parsed the jwks');

		// decode the token passed in the request headers
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		JWT::$leeway = 60;
		$payload = JWT::decode($headerToken, $jwks, array_keys(JWT::$supported_algs));

		$prettyToken = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		$this->logger->debug('Parsed the JWT payload: ' . $prettyToken);

		// check if the token has expired
		if ($payload->exp < $this->timeFactory->getTime()) {
			$this->logger->error('OIDC token has expired');
			return '';
		}

		// get or create local user from token info
		$uidAttribute = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_MAPPING_UID, 'sub');
		if (!isset($payload->{$uidAttribute})) {
			error_log('NO $payload->{$uidAttribute}');
			return '';
		}

		$realUserIds = $this->userMapper->getByRemoteUserId($payload->{$uidAttribute});
		if (count($realUserIds) === 0) {
			error_log('NO users matching token "' . $uidAttribute . '" field');
			return '';
		}
		$realUserId = $realUserIds[0];

		$backendUser = $this->userMapper->getOrCreate($provider->getId(), $realUserId);

		return $backendUser->getUserId();
	}

	private function obtainDiscovery(string $url) {
		$client = $this->clientService->newClient();

		$this->logger->debug('Obtaining discovery endpoint: ' . $url);
		$response = $client->get($url);

		$body = json_decode($response->getBody(), true);
		return $body;
	}
}
