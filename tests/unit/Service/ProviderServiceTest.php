<?php
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\IConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class ProviderServiceTest extends TestCase {

	/**
	 * @var IConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $config;
	/**
	 * @var ProviderMapper|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $providerMapper;
	/**
	 * @var ProviderService
	 */
	private $providerService;

	public function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->providerService = new ProviderService($this->config, $this->providerMapper);
	}

	public function testGetProvidersWithSettings() {
		$providers = [
			new \OCA\UserOIDC\Db\Provider(),
			new \OCA\UserOIDC\Db\Provider()
		];
		$providers[0]->setId(1);
		$providers[1]->setId(2);
		$this->providerMapper->expects(self::once())
			->method('getProviders')
			->willReturn($providers);

		$this->config->expects(self::any())
			->method('getAppValue')
			->willReturn('1');

		Assert::assertEquals([
			[
				'id' => 1,
				'identifier' => null,
				'clientId' => null,
				'discoveryEndpoint' => null,
				'endSessionEndpoint' => null,
				'scope' => null,
				'settings' => [
					'mappingDisplayName' => '1',
					'mappingEmail' => '1',
					'mappingQuota' => '1',
					'mappingUid' => '1',
					'mappingGroups' => '1',
					'mappingAddress' => '1',
					'mappingStreetaddress' => '1',
					'mappingPostalcode' => '1',
					'mappingLocality' => '1',
					'mappingRegion' => '1',
					'mappingCountry' => '1',
					'mappingWebsite' => '1',
					'mappingAvatar' => '1',
					'mappingTwitter' => '1',
					'mappingFediverse' => '1',
					'mappingOrganisation' => '1',
					'mappingRole' => '1',
					'mappingHeadline' => '1',
					'mappingBiography' => '1',
					'mappingPhonenumber' => '1',
					'mappingGender' => '1',
					'uniqueUid' => true,
					'checkBearer' => true,
					'bearerProvisioning' => true,
					'sendIdTokenHint' => true,
					'extraClaims' => '1',
					'providerBasedId' => true,
					'groupProvisioning' => true,
					'groupWhitelistRegex' => '1',
					'restrictLoginToGroups' => true,
				],
			],
			[
				'id' => 2,
				'identifier' => null,
				'clientId' => null,
				'discoveryEndpoint' => null,
				'endSessionEndpoint' => null,
				'scope' => null,
				'settings' => [
					'mappingDisplayName' => '1',
					'mappingEmail' => '1',
					'mappingQuota' => '1',
					'mappingUid' => '1',
					'mappingGroups' => '1',
					'mappingAddress' => '1',
					'mappingStreetaddress' => '1',
					'mappingPostalcode' => '1',
					'mappingLocality' => '1',
					'mappingRegion' => '1',
					'mappingCountry' => '1',
					'mappingWebsite' => '1',
					'mappingAvatar' => '1',
					'mappingTwitter' => '1',
					'mappingFediverse' => '1',
					'mappingOrganisation' => '1',
					'mappingRole' => '1',
					'mappingHeadline' => '1',
					'mappingBiography' => '1',
					'mappingPhonenumber' => '1',
					'mappingGender' => '1',
					'uniqueUid' => true,
					'checkBearer' => true,
					'bearerProvisioning' => true,
					'sendIdTokenHint' => true,
					'extraClaims' => '1',
					'providerBasedId' => true,
					'groupProvisioning' => true,
					'groupWhitelistRegex' => '1',
					'restrictLoginToGroups' => true,
				],
			],
		], $this->providerService->getProvidersWithSettings());
	}

	public function testSetSettings() {
		$defaults = [
			'mappingDisplayName' => 'dn',
			'mappingEmail' => 'mail',
			'mappingQuota' => '1g',
			'mappingUid' => 'uid',
			'mappingGroups' => 'groups',
			'uniqueUid' => true,
			'checkBearer' => false,
			'bearerProvisioning' => false,
			'sendIdTokenHint' => true,
			'extraClaims' => 'claim1 claim2',
			'providerBasedId' => false,
			'groupProvisioning' => true,
			'mappingAddress' => 'address',
			'mappingStreetaddress' => 'street_address',
			'mappingPostalcode' => 'postal_code',
			'mappingLocality' => 'locality',
			'mappingRegion' => 'region',
			'mappingCountry' => 'country',
			'mappingWebsite' => 'website',
			'mappingAvatar' => 'avatar',
			'mappingTwitter' => 'twitter',
			'mappingFediverse' => 'fediverse',
			'mappingOrganisation' => 'organisation',
			'mappingRole' => 'role',
			'mappingHeadline' => 'headline',
			'mappingBiography' => 'biography',
			'mappingPhonenumber' => 'phone',
			'mappingGender' => 'gender',
			'groupWhitelistRegex' => '',
			'restrictLoginToGroups' => false,
		];
		$this->config->expects(self::any())
			->method('getAppValue')
			->willReturnMap([
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_DISPLAYNAME, '', 'dn'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_EMAIL, '', 'mail'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_QUOTA, '', '1g'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_UID, '', 'uid'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_GROUPS, '', 'groups'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_ADDRESS, '', 'address'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_STREETADDRESS, '', 'street_address'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_POSTALCODE, '', 'postal_code'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_LOCALITY, '', 'locality'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_REGION, '', 'region'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_COUNTRY, '', 'country'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_WEBSITE, '', 'website'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_AVATAR, '', 'avatar'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_TWITTER, '', 'twitter'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_FEDIVERSE, '', 'fediverse'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_ORGANISATION, '', 'organisation'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_ROLE, '', 'role'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_HEADLINE, '', 'headline'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_BIOGRAPHY, '', 'biography'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_PHONE, '', 'phone'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_GENDER, '', 'gender'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_UNIQUE_UID, '', '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_CHECK_BEARER, '', '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_BEARER_PROVISIONING, '', '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_SEND_ID_TOKEN_HINT, '', '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_EXTRA_CLAIMS, '', 'claim1 claim2'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_PROVIDER_BASED_ID, '', '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_GROUP_PROVISIONING, '', '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_GROUP_WHITELIST_REGEX, '', ''],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS, '', '0'],
			]);

		Assert::assertEquals(
			$defaults,
			$this->providerService->setSettings(1, ['a' => ''])
		);

		Assert::assertEquals(
			array_merge($defaults, ['uniqueUid' => '0']),
			$this->providerService->setSettings(1, [ProviderService::SETTING_UNIQUE_UID => '0'])
		);

		Assert::assertEquals(
			array_merge($defaults, ['checkBearer' => '1']),
			$this->providerService->setSettings(1, [ProviderService::SETTING_CHECK_BEARER => '1'])
		);

		Assert::assertEquals(
			array_merge($defaults, ['sendIdTokenHint' => '0']),
			$this->providerService->setSettings(1, [ProviderService::SETTING_SEND_ID_TOKEN_HINT => '0'])
		);
	}

	public function testDeleteSettings() {
		$supportedConfigs = self::invokePrivate($this->providerService, 'getSupportedSettings');
		$keysToDelete = [...$supportedConfigs, ProviderService::SETTING_JWKS_CACHE, ProviderService::SETTING_JWKS_CACHE_TIMESTAMP];
		$this->config->expects(self::exactly(count($keysToDelete)))
			->method('deleteAppValue')
			->withConsecutive(...array_map(function ($setting) {
				return [Application::APP_ID, 'provider-1-' . $setting];
			}, $keysToDelete));

		$this->providerService->deleteSettings(1);
	}

	public function testSetSetting() {
		$this->config->expects(self::once())
			->method('setAppValue')
			->with(Application::APP_ID, 'provider-1-key', 'value');

		$this->providerService->setSetting(1, 'key', 'value');
	}

	public function dataGetSetting() {
		return [
			[1, 'option', '', 'ABC', 'ABC'],
			[1, 'option', 'ABCD', 'ABCD', ''],
		];
	}

	/** @dataProvider dataGetSetting */
	public function testGetSetting($providerId, $key, $stored, $expected, $default = '') {
		$this->config->expects(self::once())
			->method('getAppValue')
			->with(Application::APP_ID, 'provider-' . $providerId . '-' . $key, '')
			->willReturn($stored);

		Assert::assertEquals($expected, $this->providerService->getSetting($providerId, $key, $default));
	}

	public function dataConvertJson() {
		return [
			// Setting unique id is a boolean
			[ProviderService::SETTING_UNIQUE_UID, true, '1', true],
			[ProviderService::SETTING_UNIQUE_UID, false, '0', false],
			[ProviderService::SETTING_UNIQUE_UID, 'test', '1', true],
			// Setting check bearer is a boolean
			[ProviderService::SETTING_CHECK_BEARER, true, '1', true],
			[ProviderService::SETTING_CHECK_BEARER, false, '0', false],
			[ProviderService::SETTING_CHECK_BEARER, 'test', '1', true],
			// Setting sendIdTokenHint is a boolean
			[ProviderService::SETTING_SEND_ID_TOKEN_HINT, true, '1', true],
			[ProviderService::SETTING_SEND_ID_TOKEN_HINT, false, '0', false],
			[ProviderService::SETTING_SEND_ID_TOKEN_HINT, 'test', '1', true],
			// Any other values are just strings
			[ProviderService::SETTING_MAPPING_EMAIL, false, '', false],
			[ProviderService::SETTING_MAPPING_EMAIL, true, '1', true],
			[ProviderService::SETTING_MAPPING_EMAIL, 'test', 'test', 'test'],
			[ProviderService::SETTING_MAPPING_UID, 'test', 'test', 'test'],
			[ProviderService::SETTING_MAPPING_QUOTA, 'test', 'test', 'test'],
			[ProviderService::SETTING_MAPPING_DISPLAYNAME, 'test', 'test', 'test'],
			[ProviderService::SETTING_EXTRA_CLAIMS, 'test', 'test', 'test'],
		];
	}
	/** @dataProvider dataConvertJson */
	public function testConvertJson($key, $value, $stored, $expected) {
		$raw = self::invokePrivate($this->providerService, 'convertFromJSON', [$key, $value]);
		Assert::assertEquals($stored, $raw);
		$actual = self::invokePrivate($this->providerService, 'convertToJSON', [$key, $raw]);
		Assert::assertEquals($expected, $actual);
	}

	protected static function invokePrivate($object, $methodName, array $parameters = []) {
		if (is_string($object)) {
			$className = $object;
		} else {
			$className = get_class($object);
		}
		$reflection = new \ReflectionClass($className);

		if ($reflection->hasMethod($methodName)) {
			$method = $reflection->getMethod($methodName);

			$method->setAccessible(true);

			return $method->invokeArgs($object, $parameters);
		}

		if ($reflection->hasProperty($methodName)) {
			$property = $reflection->getProperty($methodName);

			$property->setAccessible(true);

			if (!empty($parameters)) {
				$property->setValue($object, array_pop($parameters));
			}

			if (is_object($object)) {
				return $property->getValue($object);
			}

			return $property->getValue();
		}

		return false;
	}
}
