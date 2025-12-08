<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Controller\SettingsController;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerTest extends TestCase {
	/** @var SettingsController */
	private $settingsController;

	/** @var IAppConfig | MockObject */
	private $appConfig;

	/** @var ProviderMapper | MockObject */
	private $providerMapper;

	/** @var ID4MeService | MockObject */
	private $id4meService;

	/** @var ProviderService | MockObject */
	private $providerService;

	/** @var IGroupManager | MockObject */
	private $groupManager;

	/** @var IUserManager | MockObject */
	private $userManager;

	/** @var IRequest | MockObject */
	private $request;

	/** @var LoggerInterface | MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->id4meService = $this->createMock(ID4MeService::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsController = new SettingsController(
			$this->request,
			$this->appConfig,
			$this->providerMapper,
			$this->id4meService,
			$this->providerService,
			$this->createMock(\OCP\Security\ICrypto::class),
			$this->createMock(\OCP\Http\Client\IClientService::class),
			$this->logger,
			$this->groupManager,
			$this->userManager,
		);
	}

	/**
	 * Test that resyncGroups finds and removes users from hashed groups
	 */
	public function testResyncGroupsRemovesUsersFromHashedGroups(): void {
		// Create mock groups - some are hashed (64 hex chars), some are not
		$hashedGroup1 = $this->createMock(IGroup::class);
		$hashedGroup1->method('getGID')
			->willReturn('36bc58fd6acb807bf856920a53860205c41fe0fea2737c7c8dbbef5e54892c2d'); // SHA256 hash

		$hashedGroup2 = $this->createMock(IGroup::class);
		$hashedGroup2->method('getGID')
			->willReturn('a901a3bc1234567890abcdef1234567890abcdef1234567890abcdef1234567890'); // SHA256 hash

		$normalGroup = $this->createMock(IGroup::class);
		$normalGroup->method('getGID')
			->willReturn('junovy-office-basic'); // Normal group name

		$allGroups = [$hashedGroup1, $hashedGroup2, $normalGroup];

		// Mock groupManager->search to return all groups
		$this->groupManager
			->method('search')
			->with('')
			->willReturn($allGroups);

		// Create mock users for hashed groups
		$user1 = $this->createMock(IUser::class);
		$user1->method('getBackendClassName')
			->willReturn(Application::APP_ID); // OIDC user

		$user2 = $this->createMock(IUser::class);
		$user2->method('getBackendClassName')
			->willReturn(Application::APP_ID); // OIDC user

		// Setup users for hashed groups - after removing, groups should return empty arrays
		$hashedGroup1->method('getUsers')
			->willReturnOnConsecutiveCalls([$user1], []);
		$hashedGroup2->method('getUsers')
			->willReturnOnConsecutiveCalls([$user2], []);

		// Expect users to be removed from hashed groups
		$hashedGroup1->expects(self::once())
			->method('removeUser')
			->with($user1);
		$hashedGroup2->expects(self::once())
			->method('removeUser')
			->with($user2);

		// Normal group should not be touched
		$normalGroup->expects(self::never())
			->method('removeUser');

		// Call resyncGroups
		$result = $this->settingsController->resyncGroups();

		// Verify response
		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(2, $data['stats']['hashed_groups_found']);
		$this->assertEquals(2, $data['stats']['users_removed']);
	}

	/**
	 * Test that resyncGroups ignores non-hashed groups
	 */
	public function testResyncGroupsIgnoresNormalGroups(): void {
		$normalGroup1 = $this->createMock(IGroup::class);
		$normalGroup1->method('getGID')
			->willReturn('junovy-office-basic');

		$normalGroup2 = $this->createMock(IGroup::class);
		$normalGroup2->method('getGID')
			->willReturn('rfe-admin');

		$allGroups = [$normalGroup1, $normalGroup2];

		$this->groupManager
			->method('search')
			->with('')
			->willReturn($allGroups);

		// No groups should be modified
		$normalGroup1->expects(self::never())
			->method('removeUser');
		$normalGroup2->expects(self::never())
			->method('removeUser');

		$result = $this->settingsController->resyncGroups();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(0, $data['stats']['hashed_groups_found']);
		$this->assertEquals(0, $data['stats']['users_removed']);
	}

	/**
	 * Test that resyncGroups handles empty groups correctly
	 */
	public function testResyncGroupsHandlesEmptyGroups(): void {
		$hashedGroup = $this->createMock(IGroup::class);
		$hashedGroup->method('getGID')
			->willReturn('36bc58fd6acb807bf856920a53860205c41fe0fea2737c7c8dbbef5e54892c2d');
		$hashedGroup->method('getUsers')
			->willReturn([]); // Empty group

		$this->groupManager
			->method('search')
			->with('')
			->willReturn([$hashedGroup]);

		$hashedGroup->expects(self::never())
			->method('removeUser');

		$result = $this->settingsController->resyncGroups();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['stats']['hashed_groups_found']);
		$this->assertEquals(0, $data['stats']['users_removed']);
		// Empty groups are counted as cleaned
		$this->assertEquals(1, $data['stats']['groups_cleaned']);
	}

	/**
	 * Test that resyncGroups filters by provider when providerId is specified
	 */
	public function testResyncGroupsFiltersByProvider(): void {
		$hashedGroup = $this->createMock(IGroup::class);
		$hashedGroup->method('getGID')
			->willReturn('36bc58fd6acb807bf856920a53860205c41fe0fea2737c7c8dbbef5e54892c2d');

		$oidcUser = $this->createMock(IUser::class);
		$oidcUser->method('getBackendClassName')
			->willReturn(Application::APP_ID);

		$nonOidcUser = $this->createMock(IUser::class);
		$nonOidcUser->method('getBackendClassName')
			->willReturn('database'); // Different backend

		$hashedGroup->method('getUsers')
			->willReturn([$oidcUser, $nonOidcUser]);

		$this->groupManager
			->method('search')
			->with('')
			->willReturn([$hashedGroup]);

		// Only OIDC user should be removed when providerId is specified
		$hashedGroup->expects(self::once())
			->method('removeUser')
			->with($oidcUser);

		$result = $this->settingsController->resyncGroups(123); // providerId = 123

		$this->assertInstanceOf(JSONResponse::class, $result);
		$data = $result->getData();
		$this->assertTrue($data['success']);
		$this->assertEquals(1, $data['stats']['users_removed']);
	}

	/**
	 * Test that resyncGroups handles exceptions gracefully
	 */
	public function testResyncGroupsHandlesExceptions(): void {
		$this->groupManager
			->method('search')
			->willThrowException(new \Exception('Database error'));

		$result = $this->settingsController->resyncGroups();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Failed to resync groups', $data['message']);
	}
}

