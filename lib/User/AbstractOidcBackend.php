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

use OCA\UserOIDC\Db\Provider;
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
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICustomLogout;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordConfirmationBackend;
use Psr\Log\LoggerInterface;


/**
 * Introduce a baseclass to derive multiple backend from depending on
 * the required bearer behavior.
 * 
 * The class contains the OIDC part without the bearer aspects.
 * 
 * FIXME: we should derive also the previous standard bearer backend from
 * this class
 */
abstract class AbstractOIDCBackend extends ABackend implements IPasswordConfirmationBackend, IGetDisplayNameBackend, IApacheBackend, ICustomLogout {

	/** @var UserMapper */
	protected $userMapper;
	/** @var LoggerInterface */
	protected $logger;
	/** @var IRequest */
	protected $request;
	/** @var ProviderMapper */
	protected $providerMapper;
	/**
	 * @var ProviderService
	 */
	protected $providerService;
	/**
	 * @var IConfig
	 */
	protected $config;
	/**
	 * @var IEventDispatcher
	 */
	protected $eventDispatcher;
	/**
	 * @var DiscoveryService
	 */
	protected $discoveryService;
	/**
	 * @var IURLGenerator
	 */
	protected $urlGenerator;
	/**
	 * @var ISession
	 */
	protected $session;
	/**
	 * @var IUserManager
	 */
	protected $userManager;

	public function __construct(IConfig $config,
								UserMapper $userMapper,
								LoggerInterface $logger,
								IRequest $request,
								ISession $session,
								IURLGenerator $urlGenerator,
								IEventDispatcher $eventDispatcher,
								DiscoveryService $discoveryService,
								ProviderMapper $providerMapper,
								ProviderService $providerService,
								IUserManager $userManager) {
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
		$this->userManager = $userManager;
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

	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		return $this->userMapper->findDisplayNames($search, $limit, $offset);
	}

	public function hasUserListings(): bool {
		return true;
	}

	public function canConfirmPassword(string $uid): bool {
		return false;
	}

	/**
	 * As session cannot be injected in the constructor here, we inject it later
	 *
	 * @param ISession $session
	 * @return void
	 */
	public function injectSession(ISession $session): void {
		$this->session = $session;
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
	 * Inspired by lib/private/User/Session.php::prepareUserLogin()
	 *
	 * @param string $userId
	 * @return bool
	 * @throws NotFoundException
	 */
	protected function checkFirstLogin(string $userId): bool {
		$user = $this->userManager->get($userId);

		if ($user === null) {
			return false;
		}

		$firstLogin = $user->getLastLogin() === 0;
		if ($firstLogin) {
			\OC_Util::setupFS($userId);
			// trigger creation of user home and /files folder
			$userFolder = \OC::$server->getUserFolder($userId);
			try {
				// copy skeleton
				\OC_Util::copySkeleton($userId, $userFolder);
			} catch (NotPermittedException $ex) {
				// read only uses
			}

			// trigger any other initialization
			\OC::$server->getEventDispatcher()->dispatch(IUser::class . '::firstLogin', new GenericEvent($user));
		}
		$user->updateLastLoginTimestamp();
		return $firstLogin;
	}

}
