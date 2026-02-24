<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\IAppConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class ProviderServiceTest extends TestCase {

	/**
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appConfig;
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
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->providerService = new ProviderService($this->appConfig, $this->providerMapper);
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

		$this->appConfig->expects(self::any())
			->method('getValueString')
			->willReturn('1');

		Assert::assertEquals([
			[
				'id' => 1,
				'identifier' => null,
				'clientId' => null,
				'discoveryEndpoint' => null,
				'endSessionEndpoint' => null,
				'postLogoutUri' => null,
				'scope' => null,
				'settings' => [
					'mappingDisplayName' => '1',
					'mappingEmail' => '1',
					'mappingQuota' => '1',
					'mappingUid' => '1',
					'mappingGroups' => '1',
					'mappingLanguage' => '1',
					'mappingLocale' => '1',
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
					'mappingPronouns' => '1',
					'mappingBirthdate' => '1',
					'uniqueUid' => true,
					'checkBearer' => true,
					'bearerProvisioning' => true,
					'sendIdTokenHint' => true,
					'extraClaims' => '1',
					'providerBasedId' => true,
					'groupProvisioning' => true,
					'groupWhitelistRegex' => '1',
					'restrictLoginToGroups' => true,
					'nestedAndFallbackClaims' => true,
				],
			],
			[
				'id' => 2,
				'identifier' => null,
				'clientId' => null,
				'discoveryEndpoint' => null,
				'endSessionEndpoint' => null,
				'postLogoutUri' => null,
				'scope' => null,
				'settings' => [
					'mappingDisplayName' => '1',
					'mappingEmail' => '1',
					'mappingQuota' => '1',
					'mappingUid' => '1',
					'mappingGroups' => '1',
					'mappingLanguage' => '1',
					'mappingLocale' => '1',
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
					'mappingPronouns' => '1',
					'mappingBirthdate' => '1',
					'uniqueUid' => true,
					'checkBearer' => true,
					'bearerProvisioning' => true,
					'sendIdTokenHint' => true,
					'extraClaims' => '1',
					'providerBasedId' => true,
					'groupProvisioning' => true,
					'groupWhitelistRegex' => '1',
					'restrictLoginToGroups' => true,
					'nestedAndFallbackClaims' => true,
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
			'mappingLanguage' => 'language',
			'mappingLocale' => 'locale',
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
			'mappingPronouns' => 'pronouns',
			'mappingBirthdate' => 'birthdate',
			'groupWhitelistRegex' => '',
			'restrictLoginToGroups' => false,
			'nestedAndFallbackClaims' => false,
		];
		$this->appConfig->expects(self::any())
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_DISPLAYNAME, '', true, 'dn'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_EMAIL, '', true, 'mail'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_QUOTA, '', true, '1g'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_UID, '', true, 'uid'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_GROUPS, '', true, 'groups'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_LANGUAGE, '', true, 'language'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_LOCALE, '', true, 'locale'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_ADDRESS, '', true, 'address'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_STREETADDRESS, '', true, 'street_address'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_POSTALCODE, '', true, 'postal_code'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_LOCALITY, '', true, 'locality'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_REGION, '', true, 'region'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_COUNTRY, '', true, 'country'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_WEBSITE, '', true, 'website'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_AVATAR, '', true, 'avatar'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_TWITTER, '', true, 'twitter'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_FEDIVERSE, '', true, 'fediverse'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_ORGANISATION, '', true, 'organisation'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_ROLE, '', true, 'role'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_HEADLINE, '', true, 'headline'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_BIOGRAPHY, '', true, 'biography'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_PHONE, '', true, 'phone'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_GENDER, '', true, 'gender'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_PRONOUNS, '', true, 'pronouns'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_BIRTHDATE, '', true, 'birthdate'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_UNIQUE_UID, '', true, '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_CHECK_BEARER, '', true, '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_BEARER_PROVISIONING, '', true, '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_SEND_ID_TOKEN_HINT, '', true, '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_EXTRA_CLAIMS, '', true, 'claim1 claim2'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_PROVIDER_BASED_ID, '', true, '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_GROUP_PROVISIONING, '', true, '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_GROUP_WHITELIST_REGEX, '', true, ''],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS, '', true, '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '', true, '0'],
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
		$realKeysToDelete = array_map(function ($setting) {
			return 'provider-1-' . $setting;
		}, $keysToDelete);
		$this->appConfig->expects(self::exactly(count($keysToDelete)))
			->method('deleteKey')
			->willReturnCallback(function ($appName, $key) use ($realKeysToDelete) {
				$this->assertEquals(Application::APP_ID, $appName);
				$this->assertContains($key, $realKeysToDelete);
			});

		$this->providerService->deleteSettings(1);
	}

	public function testSetSetting() {
		$this->appConfig->expects(self::once())
			->method('setValueString')
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
		$this->appConfig->expects(self::once())
			->method('getValueString')
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
