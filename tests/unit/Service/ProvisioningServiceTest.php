<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCA\UserOIDC\Service\CirclesService;
use OCA\UserOIDC\Service\LocalIdService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProvisioningServiceTest extends TestCase {
	/** @var ProvisioningService | MockObject */
	private $provisioningService;

	/** @var LocalIdService | MockObject */
	private $idService;

	/** @var ProvisioningService | MockObject */
	private $providerService;

	/** @var IConfig | MockObject */
	private $config;

	/** @var UserMapper | MockObject */
	private $userMapper;

	/** @var IUserManager | MockObject */
	private $userManager;

	/** @var IGroupManager | MockObject */
	private $groupManager;

	/** @var IEventDispatcher | MockObject */
	private $eventDispatcher;

	/** @var LoggerInterface | MockObject */
	private $logger;

	/** @var IAccountManager | MockObject */
	private $accountManager;

	/** @var IClientService | MockObject */
	private $clientService;

	/** @var IAvatarManager | MockObject */
	private $avatarManager;

	/** @var ISession | MockObject */
	private $session;
	/**
	 * @var IFactory | MockObject
	 */
	private $l10nFactory;

	/** @var CirclesService | MockObject */
	private $circlesService;

	public function setUp(): void {
		parent::setUp();
		$this->idService = $this->createMock(LocalIdService::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userMapper = $this->createMock(UserMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->avatarManager = $this->createMock(IAvatarManager::class);
		$this->session = $this->createMock(ISession::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->circlesService = $this->createMock(CirclesService::class);

		$this->provisioningService = new ProvisioningService(
			$this->idService,
			$this->providerService,
			$this->userMapper,
			$this->userManager,
			$this->groupManager,
			$this->eventDispatcher,
			$this->logger,
			$this->accountManager,
			$this->clientService,
			$this->avatarManager,
			$this->config,
			$this->session,
			$this->l10nFactory,
			$this->circlesService,
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
					[$providerId, ProviderService::SETTING_GROUP_PROVISIONING, '0', '0'],
					[$providerId, ProviderService::SETTING_MAPPING_LANGUAGE, 'language', 'language'],
					[$providerId, ProviderService::SETTING_MAPPING_LOCALE, 'locale', 'locale'],
					[$providerId, ProviderService::SETTING_MAPPING_ADDRESS, 'address', 'address'],
					[$providerId, ProviderService::SETTING_MAPPING_STREETADDRESS, 'street_address', 'street_address'],
					[$providerId, ProviderService::SETTING_MAPPING_POSTALCODE, 'postal_code', 'postal_code'],
					[$providerId, ProviderService::SETTING_MAPPING_LOCALITY, 'locality', 'locality'],
					[$providerId, ProviderService::SETTING_MAPPING_REGION, 'region', 'region'],
					[$providerId, ProviderService::SETTING_MAPPING_COUNTRY, 'country', 'country'],
					[$providerId, ProviderService::SETTING_MAPPING_WEBSITE, 'website', 'website'],
					[$providerId, ProviderService::SETTING_MAPPING_AVATAR, 'avatar', 'avatar'],
					[$providerId, ProviderService::SETTING_MAPPING_TWITTER, 'twitter', 'twitter'],
					[$providerId, ProviderService::SETTING_MAPPING_FEDIVERSE, 'fediverse', 'fediverse'],
					[$providerId, ProviderService::SETTING_MAPPING_ORGANISATION, 'organisation', 'organisation'],
					[$providerId, ProviderService::SETTING_MAPPING_ROLE, 'role', 'role'],
					[$providerId, ProviderService::SETTING_MAPPING_HEADLINE, 'headline', 'headline'],
					[$providerId, ProviderService::SETTING_MAPPING_BIOGRAPHY, 'biography', 'biography'],
					[$providerId, ProviderService::SETTING_MAPPING_PHONE, 'phone_number', 'phone_number'],
					[$providerId, ProviderService::SETTING_MAPPING_GENDER, 'gender', 'gender'],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
					[$providerId, ProviderService::SETTING_MAPPING_PRONOUNS, 'pronouns', 'pronouns'],
					[$providerId, ProviderService::SETTING_MAPPING_BIRTHDATE, 'birthdate', 'birthdate'],
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
			->method('setSystemEMailAddress')
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

	public function testProvisionUserInvalidProperties(): void {
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
					[$providerId, ProviderService::SETTING_GROUP_PROVISIONING, '0', '0'],
					[$providerId, ProviderService::SETTING_MAPPING_LANGUAGE, 'language', 'language'],
					[$providerId, ProviderService::SETTING_MAPPING_LOCALE, 'locale', 'locale'],
					[$providerId, ProviderService::SETTING_MAPPING_ADDRESS, 'address', 'address'],
					[$providerId, ProviderService::SETTING_MAPPING_STREETADDRESS, 'street_address', 'street_address'],
					[$providerId, ProviderService::SETTING_MAPPING_POSTALCODE, 'postal_code', 'postal_code'],
					[$providerId, ProviderService::SETTING_MAPPING_LOCALITY, 'locality', 'locality'],
					[$providerId, ProviderService::SETTING_MAPPING_REGION, 'region', 'region'],
					[$providerId, ProviderService::SETTING_MAPPING_COUNTRY, 'country', 'country'],
					[$providerId, ProviderService::SETTING_MAPPING_WEBSITE, 'website', 'website'],
					[$providerId, ProviderService::SETTING_MAPPING_AVATAR, 'avatar', 'avatar'],
					[$providerId, ProviderService::SETTING_MAPPING_TWITTER, 'twitter', 'twitter'],
					[$providerId, ProviderService::SETTING_MAPPING_FEDIVERSE, 'fediverse', 'fediverse'],
					[$providerId, ProviderService::SETTING_MAPPING_ORGANISATION, 'organisation', 'organisation'],
					[$providerId, ProviderService::SETTING_MAPPING_ROLE, 'role', 'role'],
					[$providerId, ProviderService::SETTING_MAPPING_HEADLINE, 'headline', 'headline'],
					[$providerId, ProviderService::SETTING_MAPPING_BIOGRAPHY, 'biography', 'biography'],
					[$providerId, ProviderService::SETTING_MAPPING_PHONE, 'phone_number', 'phone_number'],
					[$providerId, ProviderService::SETTING_MAPPING_GENDER, 'gender', 'gender'],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
					[$providerId, ProviderService::SETTING_MAPPING_PRONOUNS, 'pronouns', 'pronouns'],
					[$providerId, ProviderService::SETTING_MAPPING_BIRTHDATE, 'birthdate', 'birthdate'],
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
			->method('setSystemEMailAddress')
			->with($email);
		$user->expects(self::once())
			->method('setQuota')
			->with($quota);

		$twitterProperty = 'undefined';
		$property = $this->createMock(IAccountProperty::class);
		$property->method('getName')->willReturn('twitter');
		$property->method('getScope')->willReturn(IAccountManager::SCOPE_LOCAL);
		$property->method('getValue')->willReturnCallback(function () use (&$twitterProperty) {
			echo 'GETTING: ' . $twitterProperty;
			return $twitterProperty;
		});

		/** @var IAccount|MockObject */
		$account = $this->createMock(IAccount::class);
		$account->expects(self::exactly(2))
			->method('setProperty')
			->with('twitter', self::anything())
			->willReturnCallback(function ($_, string $prop) use (&$twitterProperty, $account) {
				$twitterProperty = $prop;
				return $account;
			});
		$account->expects(self::atLeastOnce())
			->method('getProperty')
			->with('twitter')
			->willReturn($property);


		$this->accountManager->expects(self::once())
			->method('getAccount')
			->with($user)
			->willReturn($account);

		$this->accountManager->expects(self::exactly(2))
			->method('updateAccount')
			->with($account)
			->willReturnCallback(fn ($account) => match($account->getProperty('twitter')->getValue()) {
				'' => null,
				'invalid@twitter' => throw new InvalidArgumentException(IAccountManager::PROPERTY_TWITTER),
				default => self::fail('Unexpected update account call'),
			});

		$this->provisioningService->provisionUser(
			$userId,
			$providerId,
			(object)[
				'email' => $email,
				'name' => $name,
				'quota' => $quota,
				'twitter' => 'invalid@twitter'
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
				],
				'',
				true,
			],
			[
				'group2',
				'',
				(object)[
					'groups' => [
						'group2'
					],
				],
				'',
				true,
			],
			[
				'1_Group_Import',
				'Imported from OIDC',
				(object)[
					'groups' => [
						(object)[
							'gid' => '1_Group_Import',
							'displayName' => 'Imported from OIDC',
						],
						(object)[
							'gid' => '10_Group_NoImport',
							'displayName' => 'Not Imported',
						]
					],
				],
				'/^1_/',
				false
			],
			[
				'users_nextcloud',
				'',
				(object)[
					'groups' => [
						'users_nextcloud',
						'users',
					],
				],
				'nextcloud',
				false,
			],
		];
	}

	/** @dataProvider dataProvisionUserGroups */
	public function testProvisionUserGroups(string $gid, string $displayName, object $payload, string $group_whitelist, bool $expect_delete_local_group): void {
		$user = $this->createMock(IUser::class);
		$group = $this->createMock(IGroup::class);
		$local_group = $this->createMock(IGroup::class);
		$providerId = 421;

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_GROUP_WHITELIST_REGEX, '', $group_whitelist],
					[$providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups', 'groups'],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->groupManager
			->method('getUserGroups')
			->with($user)
			->willReturn([$local_group]);

		$local_group
			->method('getGID')
			->willReturn('local_group');

		$local_group->expects($expect_delete_local_group ? self::once() : self::never())
			->method('removeUser')
			->with($user);

		// Verify that idService->getId() is NOT called for groups
		// Groups should NOT be hashed - they should use their original names
		$this->idService->expects(self::never())
			->method('getId');

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

	/**
	 * Test that groups are NOT hashed when retrieved from token
	 * This verifies the fix for the bug where groups were incorrectly hashed
	 */
	public function testGetSyncGroupsOfTokenGroupsNotHashed(): void {
		$providerId = 123;
		$originalGroupName = 'junovy-office-basic';
		$tokenPayload = (object)[
			'groups' => [
				$originalGroupName,
				'rfe-admin',
				'rfe-board'
			]
		];

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups', 'groups'],
					[$providerId, ProviderService::SETTING_GROUP_WHITELIST_REGEX, '', ''],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		// Mock the event dispatcher to return the groups as-is
		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_GROUPS) {
					// Simulate event returning the groups JSON
					$groups = ['junovy-office-basic', 'rfe-admin', 'rfe-board'];
					$event->setValue(json_encode($groups));
				}
			});

		// Verify that idService->getId() is NEVER called for groups
		$this->idService->expects(self::never())
			->method('getId');

		$result = $this->provisioningService->getSyncGroupsOfToken($providerId, $tokenPayload);

		// Verify groups are returned with their original names (not hashed)
		$this->assertNotNull($result);
		$this->assertCount(3, $result);
		$this->assertEquals($originalGroupName, $result[0]->gid);
		$this->assertEquals('rfe-admin', $result[1]->gid);
		$this->assertEquals('rfe-board', $result[2]->gid);

		// Verify none of the group IDs are hashes (64 hex characters)
		foreach ($result as $group) {
			$this->assertNotEquals(64, strlen($group->gid), 'Group ID should not be a SHA256 hash');
			$this->assertDoesNotMatchRegularExpression('/^[a-f0-9]{64}$/i', $group->gid, 'Group ID should not match SHA256 hash pattern');
		}
	}

	/**
	 * Test that groups with special characters are preserved correctly
	 */
	public function testGetSyncGroupsOfTokenPreservesSpecialCharacters(): void {
		$providerId = 456;
		$groupWithSpecialChars = 'group-with-dashes_123';
		$tokenPayload = (object)[
			'groups' => [$groupWithSpecialChars]
		];

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups', 'groups'],
					[$providerId, ProviderService::SETTING_GROUP_WHITELIST_REGEX, '', ''],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) use ($groupWithSpecialChars) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_GROUPS) {
					$event->setValue(json_encode([$groupWithSpecialChars]));
				}
			});

		$this->idService->expects(self::never())
			->method('getId');

		$result = $this->provisioningService->getSyncGroupsOfToken($providerId, $tokenPayload);

		$this->assertNotNull($result);
		$this->assertCount(1, $result);
		$this->assertEquals($groupWithSpecialChars, $result[0]->gid);
	}

	/**
	 * Test getOrganizationsFromToken with Keycloak Organizations format
	 */
	public function testGetOrganizationsFromTokenKeycloakFormat(): void {
		$providerId = 789;
		$tokenPayload = (object)[
			'organizations' => [
				'org-id-1' => ['name' => 'Engineering', 'roles' => ['member', 'admin']],
				'org-id-2' => ['name' => 'Marketing', 'roles' => ['member']],
			]
		];

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations', 'organizations'],
					[$providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '', ''],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_ORGANIZATIONS) {
					$event->setValue(json_encode([
						'org-id-1' => ['name' => 'Engineering', 'roles' => ['member', 'admin']],
						'org-id-2' => ['name' => 'Marketing', 'roles' => ['member']],
					]));
				}
			});

		$result = $this->provisioningService->getOrganizationsFromToken($providerId, $tokenPayload);

		$this->assertNotNull($result);
		$this->assertCount(2, $result);
		$this->assertEquals('org-id-1', $result[0]->id);
		$this->assertEquals('Engineering', $result[0]->name);
		$this->assertEquals(['member', 'admin'], $result[0]->roles);
		$this->assertEquals('org-id-2', $result[1]->id);
		$this->assertEquals('Marketing', $result[1]->name);
	}

	/**
	 * Test getOrganizationsFromToken with simple array format
	 */
	public function testGetOrganizationsFromTokenArrayFormat(): void {
		$providerId = 789;
		$tokenPayload = (object)[
			'organizations' => ['Engineering', 'Marketing']
		];

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations', 'organizations'],
					[$providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '', ''],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_ORGANIZATIONS) {
					$event->setValue(json_encode(['Engineering', 'Marketing']));
				}
			});

		$result = $this->provisioningService->getOrganizationsFromToken($providerId, $tokenPayload);

		$this->assertNotNull($result);
		$this->assertCount(2, $result);
		$this->assertEquals('Engineering', $result[0]->id);
		$this->assertEquals('Engineering', $result[0]->name);
		$this->assertEquals('Marketing', $result[1]->id);
		$this->assertEquals('Marketing', $result[1]->name);
	}

	/**
	 * Test getOrganizationsFromToken with whitelist regex
	 */
	public function testGetOrganizationsFromTokenWithWhitelistRegex(): void {
		$providerId = 789;
		$tokenPayload = (object)[
			'organizations' => ['eng-team', 'marketing', 'eng-backend']
		];

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations', 'organizations'],
					[$providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '', '/^eng-/'],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_ORGANIZATIONS) {
					$event->setValue(json_encode(['eng-team', 'marketing', 'eng-backend']));
				}
			});

		$result = $this->provisioningService->getOrganizationsFromToken($providerId, $tokenPayload);

		$this->assertNotNull($result);
		$this->assertCount(2, $result);
		$this->assertEquals('eng-team', $result[0]->name);
		$this->assertEquals('eng-backend', $result[1]->name);
	}

	/**
	 * Test provisionUserTeams when Circles is not enabled
	 */
	public function testProvisionUserTeamsCirclesNotEnabled(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$providerId = 789;
		$tokenPayload = (object)[
			'organizations' => ['Engineering']
		];

		$this->circlesService
			->method('isCirclesEnabled')
			->willReturn(false);

		$result = $this->provisioningService->provisionUserTeams($user, $providerId, $tokenPayload);

		$this->assertNull($result);
	}

	/**
	 * Test provisionUserTeams creates circles and adds users
	 */
	public function testProvisionUserTeamsCreatesCirclesAndAddsUsers(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$providerId = 789;
		$tokenPayload = (object)[
			'organizations' => ['Engineering', 'Marketing']
		];

		$this->circlesService
			->method('isCirclesEnabled')
			->willReturn(true);

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations', 'organizations'],
					[$providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '', ''],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_ORGANIZATIONS) {
					$event->setValue(json_encode(['Engineering', 'Marketing']));
				}
			});

		// Mock circles
		$circle1 = new \stdClass();
		$circle1->name = 'Engineering';
		$circle2 = new \stdClass();
		$circle2->name = 'Marketing';

		$this->circlesService
			->method('getUserCircles')
			->willReturn([]);

		$this->circlesService
			->method('getOrCreateCircle')
			->willReturnOnConsecutiveCalls($circle1, $circle2);

		$this->circlesService
			->expects($this->exactly(2))
			->method('addMember');

		$result = $this->provisioningService->provisionUserTeams($user, $providerId, $tokenPayload);

		$this->assertNotNull($result);
		$this->assertCount(2, $result);
	}

	/**
	 * Test provisionUserTeams removes user from circles they no longer belong to
	 */
	public function testProvisionUserTeamsRemovesUserFromOldCircles(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$providerId = 789;
		$tokenPayload = (object)[
			'organizations' => ['Engineering']
		];

		$this->circlesService
			->method('isCirclesEnabled')
			->willReturn(true);

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations', 'organizations'],
					[$providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '', ''],
					[$providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0', '0'],
				]
			));

		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function ($event) {
				if ($event instanceof AttributeMappedEvent && $event->getAttribute() === ProviderService::SETTING_MAPPING_ORGANIZATIONS) {
					$event->setValue(json_encode(['Engineering']));
				}
			});

		// Mock circles - user is currently in both Engineering and Marketing
		$oldCircle = $this->createMock(\stdClass::class);
		// We need an actual object with getDisplayName method
		$oldCircle = new class {
			public function getDisplayName(): string {
				return 'Marketing';
			}
		};

		$this->circlesService
			->method('getUserCircles')
			->willReturn([$oldCircle]);

		$newCircle = new \stdClass();
		$newCircle->name = 'Engineering';

		$this->circlesService
			->method('getOrCreateCircle')
			->willReturn($newCircle);

		// Should remove user from Marketing (the old circle)
		$this->circlesService
			->expects($this->once())
			->method('removeMember')
			->with($oldCircle, $user);

		$this->circlesService
			->expects($this->once())
			->method('addMember')
			->with($newCircle, $user);

		$result = $this->provisioningService->provisionUserTeams($user, $providerId, $tokenPayload);

		$this->assertNotNull($result);
		$this->assertCount(1, $result);
	}
}
