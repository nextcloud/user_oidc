<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
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
				'1',
				'group2',
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
				'1',
				'users_nextcloud',
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
