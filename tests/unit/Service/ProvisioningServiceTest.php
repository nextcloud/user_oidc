<?php

use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\IdService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProvisioningServiceTest extends TestCase {
	/** @var ProvisioningService | MockObject */
	private $provisioningService;

	/** @var IdService | MockObject */
	private $idService;

	/** @var ProvisioningService | MockObject */
	private $providerService;

	/** @var UserMapper | MockObject */
	private $userMapper;

	/** @var IUserManager | MockObject */
	private $userManager;

	/** @var IGroupManager | MockObject */
	private $groupManager;

	/** @var IEventDispatcher | MockObject */
	private $eventDispatcher;

	/** @var ILogger | MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->idService = $this->createMock(IdService::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->ldapService = $this->createMock(LdapService::class);
		$this->userMapper = $this->createMock(UserMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(ILogger::class);

		$this->provisioningService = new ProvisioningService(
			$this->idService,
			$this->providerService,
			$this->userMapper,
			$this->userManager,
			$this->groupManager,
			$this->eventDispatcher,
			$this->logger
		);
	}

	public function testProvisionUserAutoProvisioning(): void {
		$user = $this->createMock(IUser::class);
		$email = 'userEmail@email.com';
		$name = 'userName';
		$quota = '1234';
		$userId = 'userId123';
		$providerId = 312;

		$backendUser = $this->getMockBuilder(User::class)
			->addMethods(['getUserId', 'setUserId', 'getDisplayName', 'setDisplayName'])
			->getMock();
		$backendUser->method('getUserId')
			->willReturn($userId);

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email', 'email'],
					[$providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name', 'name'],
					[$providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota', 'quota'],
					[$providerId, ProviderService::SETTING_GROUP_PROVISIONING, '0', '0']
				]
			));

		$this->userMapper
			->method('getOrCreate')
			->willReturn($backendUser);

		$this->userManager
			->method('get')
			->willReturn($user);

		$backendUser->expects(self::once())
			->method('setDisplayName')
			->with($name);
		$user->expects(self::once())
			->method('setEMailAddress')
			->with($email);
		$user->expects(self::once())
			->method('setQuota')
			->with($quota);

		$this->provisioningService->provisionUser(
			$userId,
			$providerId,
			(object)[
				'email' => $email,
				'name' => $name,
				'quota' => $quota
			]
		);
	}

	public function dataProvisionUserGroups() {
		return [
			[
				'1',
				'groupName1',
				(object)[
					'groups' => [
						(object)[
							'gid' => '1',
							'displayName' => 'groupName1'
						]
					],
				]
			],
			[
				'1',
				'',
				(object)[
					'groups' => [
						'group2'
					],
				]
			],
		];
	}

	/** @dataProvider dataProvisionUserGroups */
	public function testProvisionUserGroups(string $gid, string $displayName, object $payload): void {
		$user = $this->createMock(IUser::class);
		$group = $this->createMock(IGroup::class);
		$providerId = 421;

		$this->providerService
			->method('getSetting')
			->with($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups')
			->willReturn('groups');

		$this->groupManager
			->method('getUserGroups')
			->with($user)
			->willReturn([]);

		$this->idService->method('getId')
			->willReturn($gid);

		$this->groupManager->expects(self::once())
			->method('createGroup')
			->with($gid)
			->willReturn($group);

		$group->expects(self::once())
			->method('addUser')
			->with($user);
		$group->expects(empty($displayName) ? self::never() : self::once())
			->method('setDisplayName')
			->with($displayName);

		$this->provisioningService->provisionUserGroups(
			$user,
			$providerId,
			$payload
		);
	}
}
