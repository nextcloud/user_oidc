<?php
/*
 * @copyright Copyright (c) 2021 T-Systems International
 *
 * @author Bernd Rederlechner <bernd.rederlechner@t-systems.com>
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

use OCP\IRequest;
use OCP\IConfig;

use OCA\UserOIDC\AppInfo\Application;

use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;

use OCP\Security\ICrypto;

use OCA\UserOIDC\Command\UpsertProvider;
use Symfony\Component\Console\Tester\CommandTester;


use PHPUnit\Framework\TestCase;

class BearerSettingsTest extends TestCase {
	/**
	 * @var ProviderService
	 */
	private $provider;

	/**
	 * @var IConfig;
	 */
	private $config;

	public function setUp(): void {
		parent::setUp();

		$app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->requestMock = $this->createMock(IRequest::class);

		$this->config = $this->createMock(IConfig::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$providers = [
			new \OCA\UserOIDC\Db\Provider(),
		];
		$providers[0]->setId(1);
		$providers[0]->setIdentifier('Fraesbook');

		$this->providerMapper->expects(self::any())
			->method('getProviders')
			->willReturn($providers);

		$this->providerService = $this->getMockBuilder(ProviderService::class)
								->setConstructorArgs([ $this->config, $this->providerMapper])
								->onlyMethods(['getProviderByIdentifier'])
								->getMock();
		$this->crypto = $app->getContainer()->get(ICrypto::class);
	}

	protected function mockCreateUpdate(
		string $providername,
		string|null $clientid,
		string|null $clientsecret,
		string|null $discovery,
		string $scope,
		string|null $bearersecret,
		array $options,
		int $id = 2
	) {
		$provider = $this->getMockBuilder(Provider::class)
						->addMethods(['getIdentifier', 'getId'])
						->getMock();
		$provider->expects($this->any())
						->method('getIdentifier')
						->willReturn($providername);
		$provider->expects($this->any())
						->method('getId')
						->willReturn($id);

		$this->providerMapper->expects($this->once())
							->method('createOrUpdateProvider')
							->with(
								$this->equalTo($providername),
								$this->equalTo($clientid),
								$this->anything(),
								$this->equalTo($discovery),
								$this->equalTo($scope),
								$this->anything()
							)
							->willReturnCallback(function ($id, $clientid, $secret, $discovery, $scope, $bsecret) use ($clientsecret, $bearersecret, $provider) {
								if ($secret !== null) {
									$this->assertEquals($clientsecret, $this->crypto->decrypt($secret));
								} else {
									$this->assertNull($secret);
								}
								if ($bsecret !== null) {
									$this->assertEquals($bearersecret, \Base64Url\Base64Url::decode($this->crypto->decrypt($bsecret)));
								} else {
									$this->assertNull($bsecret);
								}
								return $provider;
							});


		$this->config->expects($this->any())
					->method('setAppValue')
					->with($this->equalTo(Application::APP_ID), $this->anything(), $this->anything())
					->willReturnCallback(function ($appid, $key, $value) use ($options) {
						if (array_key_exists($key, $options)) {
							$this->assertEquals($options[$key], $value);
						}
						return '';
					});
	}


	public function testCommandAddProvider() {
		$this->providerService->expects($this->once())
								->method('getProviderByIdentifier')
								->with($this->equalTo('Telekom'))
								->willReturn(null);

		$this->mockCreateUpdate('Telekom',
							'10TVL0SAM30000004901NEXTMAGENTACLOUDTEST',
							'clientsecret***',
							'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
							'openid email profile',
							'bearersecret***',
							[
								'provider-2-' . ProviderService::SETTING_UNIQUE_UID => '0',
								'provider-2-' . ProviderService::SETTING_MAPPING_DISPLAYNAME => 'urn:telekom.com:displayname',
								'provider-2-' . ProviderService::SETTING_MAPPING_EMAIL => 'urn:telekom.com:mainEmail',
								'provider-2-' . ProviderService::SETTING_MAPPING_QUOTA => 'quota',
								'provider-2-' . ProviderService::SETTING_MAPPING_UID => 'sub'
							]);

		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--clientid' => '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST',
			'--clientsecret' => 'clientsecret***',
			'--bearersecret' => 'bearersecret***',
			'--discoveryuri' => 'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
			'--scope' => 'openid email profile',
			'--unique-uid' => '0',
			'--mapping-display-name' => 'urn:telekom.com:displayname',
			'--mapping-email' => 'urn:telekom.com:mainEmail',
			'--mapping-quota' => 'quota',
			'--mapping-uid' => 'sub',
		));


		//$output = $commandTester->getOutput();
		//$this->assertContains('done', $output);
	}

	protected function mockProvider(string $providername,
									string $clientid,
									string $clientsecret,
									string $discovery,
									string $scope,
									string $bearersecret,
									int $id = 2) : Provider {
		$provider = $this->getMockBuilder(Provider::class)
						->addMethods(['getIdentifier', 'getClientId', 'getClientSecret', 'getBearerSecret', 'getDiscoveryEndpoint'])
						->setMethods(['getScope', 'getId'])
						->getMock();
		$provider->expects($this->any())
						->method('getIdentifier')
						->willReturn($providername);
		$provider->expects($this->any())
						->method('getId')
						->willReturn(2);
		$provider->expects($this->any())
						->method('getClientId')
						->willReturn($clientid);
		$provider->expects($this->any())
						->method('getClientSecret')
						->willReturn($clientsecret);
		$provider->expects($this->any())
						->method('getBearerSecret')
						->willReturn(\Base64Url\Base64Url::encode($bearersecret));
		$provider->expects($this->any())
						->method('getDiscoveryEndpoint')
						->willReturn($discovery);
		$provider->expects($this->any())
						->method('getScope')
						->willReturn($scope);
										
		return $provider;
	}

	public function testCommandUpdateFull() {
		$provider = $this->getMockBuilder(Provider::class)
					->addMethods(['getIdentifier', 'getClientId', 'getClientSecret', 'getBearerSecret', 'getDiscoveryEndpoint'])
					->setMethods(['getScope'])
					->getMock();
		$provider->expects($this->any())
				->method('getIdentifier')
				->willReturn('Telekom');
		$provider->expects($this->never())->method('getClientId');
		$provider->expects($this->never())->method('getClientSecret');
		$provider->expects($this->never())->method('getBearerSecret');
		$provider->expects($this->never())->method('getDiscoveryEndpoint');
		$provider->expects($this->never())->method('getScope');

		$this->providerService->expects($this->once())
				->method('getProviderByIdentifier')
				->with($this->equalTo('Telekom'))
				->willReturn(null);
		$this->mockCreateUpdate('Telekom',
				'10TVL0SAM30000004902NEXTMAGENTACLOUDTEST',
				'client*secret***',
				'https://accounts.login00.idm.ver.sul.t-online.de/.well-unknown/openid-configuration',
				'openid profile',
				'bearer*secret***',
				[
					'provider-2-' . ProviderService::SETTING_UNIQUE_UID => '1',
					'provider-2-' . ProviderService::SETTING_MAPPING_DISPLAYNAME => 'urn:telekom.com:displaykrame',
					'provider-2-' . ProviderService::SETTING_MAPPING_EMAIL => 'urn:telekom.com:mainDemail',
					'provider-2-' . ProviderService::SETTING_MAPPING_QUOTA => 'quotas',
					'provider-2-' . ProviderService::SETTING_MAPPING_UID => 'flop'
				]);

		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);
		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--clientid' => '10TVL0SAM30000004902NEXTMAGENTACLOUDTEST',
			'--clientsecret' => 'client*secret***',
			'--bearersecret' => 'bearer*secret***',
			'--discoveryuri' => 'https://accounts.login00.idm.ver.sul.t-online.de/.well-unknown/openid-configuration',
			'--scope' => 'openid profile',
			'--mapping-display-name' => 'urn:telekom.com:displaykrame',
			'--mapping-email' => 'urn:telekom.com:mainDemail',
			'--mapping-quota' => 'quotas',
			'--mapping-uid' => 'flop',
			'--unique-uid' => '1'
		));
	}

	public function testCommandUpdateSingleClientId() {
		$provider = $this->mockProvider('Telekom', '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST', 'clientsecret***',
							'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
							'openid email profile', 'bearersecret***');
		$this->providerService->expects($this->once())
									->method('getProviderByIdentifier')
									->with($this->equalTo('Telekom'))
									->willReturn($provider);
		$this->mockCreateUpdate(
			'Telekom',
			'10TVL0SAM30000004903NEXTMAGENTACLOUDTEST',
			null,
			null,
			'openid email profile',
			null,
			[]);

		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--clientid' => '10TVL0SAM30000004903NEXTMAGENTACLOUDTEST',
		));
	}


	public function testCommandUpdateSingleClientSecret() {
		$provider = $this->mockProvider('Telekom', '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST', 'clientsecret***',
							'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
							'openid email profile', 'bearersecret***');
		$this->providerService->expects($this->once())
									->method('getProviderByIdentifier')
									->with($this->equalTo('Telekom'))
									->willReturn($provider);
		$this->mockCreateUpdate(
			'Telekom',
			null,
			'***clientsecret***',
			null,
			'openid email profile',
			null,
			[]);
 
		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--clientsecret' => '***clientsecret***',
		));
	}

	public function testCommandUpdateSingleBearerSecret() {
		$provider = $this->mockProvider('Telekom', '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST', 'clientsecret***',
							'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
							'openid email profile', 'bearersecret***');
		$this->providerService->expects($this->once())
									->method('getProviderByIdentifier')
									->with($this->equalTo('Telekom'))
									->willReturn($provider);
		$this->mockCreateUpdate(
			'Telekom',
			null,
			null,
			null,
			'openid email profile',
			'***bearersecret***',
			[]);
 

		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--bearersecret' => '***bearersecret***',
		));
	}

	public function testCommandUpdateSingleDiscoveryEndpoint() {
		$provider = $this->mockProvider('Telekom', '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST', 'clientsecret***',
		'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
		'openid email profile', 'bearersecret***');
		$this->providerService->expects($this->once())
				->method('getProviderByIdentifier')
				->with($this->equalTo('Telekom'))
				->willReturn($provider);
		$this->mockCreateUpdate(
				'Telekom',
				null,
				null,
				'https://accounts.login00.idm.ver.sul.t-online.de/.well-unknown/openid-configuration',
				'openid email profile',
				null, []);

		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--discoveryuri' => 'https://accounts.login00.idm.ver.sul.t-online.de/.well-unknown/openid-configuration',
		));
	}

	public function testCommandUpdateSingleScope() {
		$provider = $this->mockProvider('Telekom', '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST', 'clientsecret***',
							'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
							'openid email profile', 'bearersecret***');
		$this->providerService->expects($this->once())
									->method('getProviderByIdentifier')
									->with($this->equalTo('Telekom'))
									->willReturn($provider);
		$this->mockCreateUpdate(
			'Telekom',
			null,
			null,
			null,
			'openid profile',
			'***bearersecret***',
			[]);
 

		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--scope' => 'openid profile',
		));
	}

	public function testCommandUpdateSingleUniqueUid() {
		$provider = $this->mockProvider('Telekom', '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST', 'clientsecret***',
							'https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration',
							'openid email profile', 'bearersecret***');
		$this->providerService->expects($this->once())
									->method('getProviderByIdentifier')
									->with($this->equalTo('Telekom'))
									->willReturn($provider);
		$this->mockCreateUpdate(
			'Telekom',
			null,
			null,
			null,
			'openid email profile',
			null,
			['provider-2-' . ProviderService::SETTING_UNIQUE_UID => '1']);
 
		$command = new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto);
		$commandTester = new CommandTester($command);

		$commandTester->execute(array(
			'identifier' => 'Telekom',
			'--unique-uid' => '1',
		));
	}
}
