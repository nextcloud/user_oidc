<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
				'scope' => null,
				'settings' => [
					'mappingDisplayName' => '1',
					'mappingEmail' => '1',
					'mappingQuota' => '1',
					'mappingUid' => '1',
					'mappingGroups' => '1',
					'uniqueUid' => true,
					'checkBearer' => true,
					'bearerProvisioning' => true,
					'sendIdTokenHint' => true,
					'extraClaims' => '1',
					'providerBasedId' => true,
					'groupProvisioning' => true,
				],
			],
			[
				'id' => 2,
				'identifier' => null,
				'clientId' => null,
				'discoveryEndpoint' => null,
				'scope' => null,
				'settings' => [
					'mappingDisplayName' => '1',
					'mappingEmail' => '1',
					'mappingQuota' => '1',
					'mappingUid' => '1',
					'mappingGroups' => '1',
					'uniqueUid' => true,
					'checkBearer' => true,
					'bearerProvisioning' => true,
					'sendIdTokenHint' => true,
					'extraClaims' => '1',
					'providerBasedId' => true,
					'groupProvisioning' => true,
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
		];
		$this->config->expects(self::any())
			->method('getAppValue')
			->willReturnMap([
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_DISPLAYNAME, '', 'dn'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_EMAIL, '', 'mail'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_QUOTA, '', '1g'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_UID, '', 'uid'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_MAPPING_GROUPS, '', 'groups'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_UNIQUE_UID, '', '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_CHECK_BEARER, '', '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_BEARER_PROVISIONING, '', '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_SEND_ID_TOKEN_HINT, '', '1'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_EXTRA_CLAIMS, '', 'claim1 claim2'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_PROVIDER_BASED_ID, '', '0'],
				[Application::APP_ID, 'provider-1-' . ProviderService::SETTING_GROUP_PROVISIONING, '', '1'],
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
		$this->config->expects(self::exactly(count($supportedConfigs)))
			->method('deleteAppValue')
			->withConsecutive(...array_map(function ($setting) {
				return [Application::APP_ID, 'provider-1-' . $setting];
			}, $supportedConfigs));

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
