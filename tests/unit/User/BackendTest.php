<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\User\Backend;
use OCA\UserOIDC\User\Validator\SelfEncodedValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for the bearer token path of the IApacheBackend implementation.
 *
 * getCurrentUserId() runs as the very first statement of OC_User::loginWithApache(),
 * which then guards the whole login block on the session user not being set yet:
 *
 *     $uid = $backend->getCurrentUserId();
 *     if ($uid) {
 *         if (self::getUser() !== $uid) {          // <- closed if we set the user above
 *             self::setUserId($uid);
 *             ...
 *             $userSession->createSessionToken($request, $uid, $uid, $password);
 *
 * OC_User::getUser() reads the session key 'user_id', which is exactly what
 * IUserSession::setUser() writes. So setting the session user from inside
 * getCurrentUserId() closes that guard and no oc_authtoken row is ever written for
 * the request, which breaks every endpoint that needs a session token afterwards.
 *
 * The backend must therefore only *resolve* the user and leave logging them in to
 * the server. Note that setVolatileActiveUser() is deliberately not covered here:
 * it does not persist 'user_id' and so does not close the guard.
 *
 * @see https://github.com/nextcloud/user_oidc/issues/1452
 *
 * Extends \Test\TestCase (not PHPUnit's) for overwriteService(), because the backend
 * resolves IUserSession and the token validators through Server::get().
 */
class BackendTest extends \Test\TestCase {
	private const TOKEN_USER_ID = 'oidc-token-user';
	private const PROVIDER_ID = 1;

	private IConfig&MockObject $config;
	private UserMapper&MockObject $userMapper;
	private LoggerInterface&MockObject $logger;
	private IRequest&MockObject $request;
	private ISession&MockObject $session;
	private IURLGenerator&MockObject $urlGenerator;
	private IEventDispatcher&MockObject $eventDispatcher;
	private DiscoveryService&MockObject $discoveryService;
	private ProviderMapper&MockObject $providerMapper;
	private ProviderService&MockObject $providerService;
	private ProvisioningService&MockObject $provisioningService;
	private LdapService&MockObject $ldapService;
	private IUserManager&MockObject $userManager;
	private ITimeFactory&MockObject $timeFactory;

	private Backend $backend;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->userMapper = $this->createMock(UserMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->provisioningService = $this->createMock(ProvisioningService::class);
		$this->ldapService = $this->createMock(LdapService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->backend = new Backend(
			$this->config,
			$this->userMapper,
			$this->logger,
			$this->request,
			$this->session,
			$this->urlGenerator,
			$this->eventDispatcher,
			$this->discoveryService,
			$this->providerMapper,
			$this->providerService,
			$this->provisioningService,
			$this->ldapService,
			$this->userManager,
			$this->timeFactory,
		);
	}

	/**
	 * Auto-provisioning enabled (the default): the user already exists, so it is
	 * reused instead of being created.
	 */
	public function testBearerAuthWithAutoProvisioningDoesNotLogTheUserIn(): void {
		$this->givenAValidBearerToken();

		$user = $this->givenAUserThatHasLoggedInBefore(self::TOKEN_USER_ID);
		$this->userManager->method('userExists')->with(self::TOKEN_USER_ID)->willReturn(true);
		$this->userManager->method('get')->with(self::TOKEN_USER_ID)->willReturn($user);
		$this->ldapService->method('isLdapDeletedUser')->with($user)->willReturn(false);

		$this->assertResolvesUserWithoutLoggingIn(self::TOKEN_USER_ID);
	}

	/**
	 * Auto-provisioning disabled and the user is known to this backend.
	 */
	public function testBearerAuthWithoutAutoProvisioningDoesNotLogTheUserIn(): void {
		$this->givenAValidBearerToken(['auto_provision' => false]);

		$user = $this->givenAUserThatHasLoggedInBefore(self::TOKEN_USER_ID);
		$this->userMapper->method('userExists')->with(self::TOKEN_USER_ID)->willReturn(true);
		$this->userManager->method('get')->with(self::TOKEN_USER_ID)->willReturn($user);

		$this->assertResolvesUserWithoutLoggingIn(self::TOKEN_USER_ID);
	}

	/**
	 * Auto-provisioning disabled and the user lives in another backend (for
	 * instance synced from LDAP).
	 */
	public function testBearerAuthForUserOfAnotherBackendDoesNotLogTheUserIn(): void {
		$this->givenAValidBearerToken(['auto_provision' => false]);

		$user = $this->givenAUserThatHasLoggedInBefore(self::TOKEN_USER_ID);
		$this->userMapper->method('userExists')->with(self::TOKEN_USER_ID)->willReturn(false);
		$this->userManager->method('userExists')->with(self::TOKEN_USER_ID)->willReturn(true);
		$this->userManager->method('get')->with(self::TOKEN_USER_ID)->willReturn($user);
		$this->ldapService->method('isLdapDeletedUser')->with($user)->willReturn(false);

		$this->assertResolvesUserWithoutLoggingIn(self::TOKEN_USER_ID);
	}

	/**
	 * Wires up a request carrying a bearer token that the self encoded validator
	 * accepts for a provider with bearer checking turned on.
	 *
	 * @param array<string, mixed> $systemConfig the 'user_oidc' system config
	 */
	private function givenAValidBearerToken(array $systemConfig = []): void {
		$this->config->method('getSystemValue')->with('user_oidc', [])->willReturn($systemConfig);
		$this->request->method('getHeader')
			->with(Application::OIDC_API_REQ_HEADER)
			->willReturn('Bearer a-valid-token');

		$provider = new Provider();
		$provider->setId(self::PROVIDER_ID);
		$provider->setIdentifier('test-provider');
		$this->providerMapper->method('getProviders')->willReturn([$provider]);

		$this->providerService->method('getSetting')->willReturnMap([
			[self::PROVIDER_ID, ProviderService::SETTING_CHECK_BEARER, '0', '1'],
			[self::PROVIDER_ID, ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS, '0', '0'],
			[self::PROVIDER_ID, ProviderService::SETTING_BEARER_PROVISIONING, '0', '0'],
		]);

		$this->discoveryService->method('obtainDiscovery')->willReturn([]);
		$this->timeFactory->method('getTime')->willReturn(1700000000);

		$validator = $this->createMock(SelfEncodedValidator::class);
		$validator->method('isValidBearerToken')->willReturn(self::TOKEN_USER_ID);
		$this->overwriteService(SelfEncodedValidator::class, $validator);
	}

	private function givenAUserThatHasLoggedInBefore(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		// a non-zero last login keeps checkFirstLogin() away from the filesystem
		$user->method('getLastLogin')->willReturn(1600000000);
		$user->method('getBackendClassName')->willReturn(Application::APP_ID);

		return $user;
	}

	/**
	 * The regression: the bearer token must resolve to $expectedUserId without any
	 * of it landing in the user session, so that OC_User::loginWithApache() still
	 * runs createSessionToken() and the rest of the login.
	 */
	private function assertResolvesUserWithoutLoggingIn(string $expectedUserId): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())
			->method('setUser');
		$this->overwriteService(IUserSession::class, $userSession);
		// without this the never() expectation above would hold vacuously and the
		// test would stay green even with the session user being set
		$this->assertSame(
			$userSession,
			Server::get(IUserSession::class),
			'the IUserSession mock did not replace the real service'
		);

		$this->session->method('set')->willReturnCallback(
			function (string $key, mixed $value): void {
				$this->assertNotSame(
					'user_id',
					$key,
					'getCurrentUserId() must not put the user into the session: OC_User::loginWithApache() '
					. 'then skips createSessionToken() and the request gets no auth token.'
				);
			}
		);

		$this->assertSame($expectedUserId, $this->backend->getCurrentUserId());
	}
}
