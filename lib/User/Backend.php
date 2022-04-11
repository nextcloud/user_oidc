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

use OCA\UserOIDC\Event\TokenValidatedEvent;
use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\User\Validator\SelfEncodedValidator;
use OCA\UserOIDC\User\Validator\UserInfoValidator;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\IApacheBackend;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICustomLogout;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordConfirmationBackend;
use OCP\UserInterface;
use Psr\Log\LoggerInterface;

class Backend extends ABackend implements IPasswordConfirmationBackend, IGetDisplayNameBackend, IApacheBackend, ICustomLogout {
	private $tokenValidators = [
		SelfEncodedValidator::class,
		UserInfoValidator::class,
	];

	/** @var UserInterface[] */
	private static $backends = [];

	/** @var UserMapper */
	private $userMapper;
	/** @var LoggerInterface */
	private $logger;
	/** @var IRequest */
	private $request;
	/** @var ProviderMapper */
	private $providerMapper;
	/**
	 * @var ProviderService
	 */
	private $providerService;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IEventDispatcher
	 */
	private $eventDispatcher;
	/**
	 * @var DiscoveryService
	 */
	private $discoveryService;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var ISession
	 */
	private $session;

	public function __construct(IConfig $config,
								UserMapper $userMapper,
								LoggerInterface $logger,
								IRequest $request,
								ISession $session,
								IURLGenerator $urlGenerator,
								IEventDispatcher $eventDispatcher,
								DiscoveryService $discoveryService,
								ProviderMapper $providerMapper,
								ProviderService $providerService) {
		$this->config = $config;
		$this->userMapper = $userMapper;
		$this->logger = $logger;
		$this->request = $request;
		$this->providerMapper = $providerMapper;
		$this->providerService = $providerService;
		$this->eventDispatcher = $eventDispatcher;
		$this->discoveryService = $discoveryService;
		$this->session = $session;
		$this->urlGenerator = $urlGenerator;
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
		if ($backend = $this->getActualUserBackend($uid)) {
			return $backend->userExists($uid);
		} else {
			return $this->userMapper->userExists($uid);
		}
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
	 * Gets the actual user backend of the user
	 *
	 * @param string $uid
	 * @return null|UserInterface
	 */
	public function getActualUserBackend($uid): ?UserInterface {
		foreach (self::$backends as $backend) {
			if ($backend->userExists($uid)) {
				return $backend;
			}
		}
		return null;
	}

	/**
	 * Registers the used backends, used later to get the actual user backend
	 * of the user.
	 *
	 * @param UserInterface[] $backends
	 */
	public function registerBackends(array $backends) {
		self::$backends = $backends;
	}

	/**
	 * In case the user has been authenticated by Apache true is returned.
	 *
	 * @return boolean whether Apache reports a user as currently logged in.
	 * @since 6.0.0
	 */
	public function isSessionActive() {
		// if this returns true, getCurrentUserId is called
		// not sure if we should rather to the validation in here as otherwise it might fail for other backends or bave other side effects
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		// session is active if we have a bearer token (API request) OR if we logged in via user_oidc (we have a provider ID in the session)
		// TODO maybe only check the session stuff if a "singleLogout option" is enabled
		return $headerToken !== '' || $this->session->get(LoginController::PROVIDERID) !== null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLogoutUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'user_oidc.login.singleLogoutService',
			[
				'requesttoken' => \OC::$server->getCsrfTokenManager()->getToken()->getEncryptedValue(),
			]
		);
	}

	/**
	 * Return the id of the current user
	 * @return string
	 * @since 6.0.0
	 */
	public function getCurrentUserId() {
		$providers = $this->providerMapper->getProviders();
		if (count($providers) === 0) {
			$this->logger->error('no OIDC providers');
			return '';
		}

		// get the bearer token from headers
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		$headerToken = preg_replace('/^bearer\s+/i', '', $headerToken);
		if ($headerToken === '') {
			$this->logger->error('No Bearer token');
			return '';
		}

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		// check if we should use UserInfoValidator (default is false)
		if (!isset($oidcSystemConfig['userinfo_bearer_validation']) || !$oidcSystemConfig['userinfo_bearer_validation']) {
			if (($key = array_search(UserInfoValidator::class, $this->tokenValidators)) !== false) {
				unset($this->tokenValidators[$key]);
			}
		}
		// check if we should use SelfEncodedValidator (default is true)
		if (isset($oidcSystemConfig['selfencoded_bearer_validation']) && !$oidcSystemConfig['selfencoded_bearer_validation']) {
			if (($key = array_search(SelfEncodedValidator::class, $this->tokenValidators)) !== false) {
				unset($this->tokenValidators[$key]);
			}
		}

		$autoProvisionAllowed = (!isset($oidcSystemConfig['auto_provision']) || $oidcSystemConfig['auto_provision']);

		// try to validate with all providers
		foreach ($providers as $provider) {
			if ($this->providerService->getSetting($provider->getId(), ProviderService::SETTING_CHECK_BEARER, '0') === '1') {
				// find user id through different token validation methods
				foreach ($this->tokenValidators as $validatorClass) {
					$validator = \OC::$server->get($validatorClass);
					$userId = $validator->isValidBearerToken($provider, $headerToken);
					if ($userId) {
						$this->logger->debug(
							'Token validated with ' . $validatorClass . ' by provider: ' . $provider->getId()
								. ' (' . $provider->getIdentifier() . ')'
						);
						$discovery = $this->discoveryService->obtainDiscovery($provider);
						$this->eventDispatcher->dispatchTyped(new TokenValidatedEvent(['token' => $headerToken], $provider, $discovery));
						if ($autoProvisionAllowed) {
							$backendUser = $this->userMapper->getOrCreate($provider->getId(), $userId);
							return $backendUser->getUserId();
						} elseif ($this->userExists($userId)) {
							return $userId;
						}
						return '';
					}
				}
			}
		}

		$this->logger->error('Could not find unique token validation');
		return '';
	}
}
