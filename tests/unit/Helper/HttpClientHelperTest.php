<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpClientHelperTest extends TestCase {

	private const LOCAL_PROVIDER = 'http://idp.lan/realms/home/.well-known/openid-configuration';

	private IClient&MockObject $client;
	private IConfig&MockObject $config;
	private ProviderMapper&MockObject $providerMapper;
	private HttpClientHelper $helper;

	public function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->config = $this->createMock(IConfig::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->helper = new HttpClientHelper($clientService, $this->config, $this->providerMapper);
	}

	/**
	 * @param array<string, mixed> $systemConfig the 'user_oidc' system config
	 */
	private function givenSystemConfig(array $systemConfig, bool $debug = false): void {
		$this->config->method('getSystemValue')->with('user_oidc', [])->willReturn($systemConfig);
		$this->config->method('getSystemValueBool')->with('debug', false)->willReturn($debug);
	}

	private function givenProviders(?string ...$discoveryEndpoints): void {
		$providers = array_map(static function (?string $discoveryEndpoint) {
			$provider = new Provider();
			$provider->setDiscoveryEndpoint($discoveryEndpoint);
			return $provider;
		}, $discoveryEndpoints);
		$this->providerMapper->method('getProviders')->willReturn($providers);
	}

	/**
	 * Expects one $method request to $url, sent with the given local address and
	 * certificate verification options.
	 */
	private function expectRequest(string $method, string $url, bool $allowLocalAddress, bool $verify = true): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('body');
		$this->client->expects(self::once())
			->method($method)
			->with($url, self::callback(static function (array $options) use ($allowLocalAddress, $verify) {
				return ($options['nextcloud']['allow_local_address'] ?? false) === $allowLocalAddress
					&& ($options['verify'] ?? true) === $verify;
			}))
			->willReturn($response);
	}

	public function testOptionUnsetKeepsProtection(): void {
		$this->givenSystemConfig([]);
		$this->providerMapper->expects(self::never())->method('getProviders');
		$this->expectRequest('get', 'http://idp.lan/realms/home/protocol/openid-connect/certs', false);
		$this->expectRequest('post', 'http://idp.lan/realms/home/protocol/openid-connect/token', false);

		$this->helper->get('http://idp.lan/realms/home/protocol/openid-connect/certs');
		$this->helper->post('http://idp.lan/realms/home/protocol/openid-connect/token', 'grant_type=authorization_code');
	}

	public static function dataProviderHostMatching() {
		return [
			'provider host' => [[self::LOCAL_PROVIDER], 'http://idp.lan/realms/home/protocol/openid-connect/certs', true],
			'second provider host' => [['https://login.example.org/.well-known/openid-configuration', self::LOCAL_PROVIDER], 'http://idp.lan/token', true],
			'host in another case' => [['http://IdP.LAN/.well-known/openid-configuration'], 'http://idp.lan/token', true],
			'other host' => [[self::LOCAL_PROVIDER], 'http://192.168.1.1/', false],
			'provider host as a subdomain' => [[self::LOCAL_PROVIDER], 'http://idp.lan.example.org/token', false],
			'url without a host' => [[self::LOCAL_PROVIDER], 'not a url', false],
			'providers without a host' => [[null, '', 'not a url', self::LOCAL_PROVIDER], 'http://idp.lan/token', true],
		];
	}

	#[DataProvider('dataProviderHostMatching')]
	public function testProviderHostMatching(array $discoveryEndpoints, string $url, bool $allowLocalAddress): void {
		$this->givenSystemConfig(['httpclient.allowlocalprovider' => true]);
		$this->givenProviders(...$discoveryEndpoints);
		$this->expectRequest('get', $url, $allowLocalAddress);

		$this->helper->get($url);
	}

	public function testPostToProviderHostAllowsLocalAddress(): void {
		$this->givenSystemConfig(['httpclient.allowlocalprovider' => true]);
		$this->givenProviders(self::LOCAL_PROVIDER);
		$this->expectRequest('post', 'http://idp.lan/realms/home/protocol/openid-connect/token', true);

		$this->helper->post('http://idp.lan/realms/home/protocol/openid-connect/token', 'grant_type=authorization_code');
	}

	public static function dataOptionParsing() {
		return [
			'true' => [true, true],
			'string 1' => ['1', true],
			'false' => [false, false],
			'string false' => ['false', false],
		];
	}

	#[DataProvider('dataOptionParsing')]
	public function testOptionParsing($value, bool $allowLocalAddress): void {
		$this->givenSystemConfig(['httpclient.allowlocalprovider' => $value]);
		$this->givenProviders(self::LOCAL_PROVIDER);
		$this->expectRequest('get', self::LOCAL_PROVIDER, $allowLocalAddress);

		$this->helper->get(self::LOCAL_PROVIDER);
	}

	public static function dataAllowSelfSigned() {
		return [
			'unset' => [[], false, true],
			'on' => [['httpclient.allowselfsigned' => true], false, false],
			'string false' => [['httpclient.allowselfsigned' => 'false'], false, true],
			'debug' => [[], true, false],
		];
	}

	#[DataProvider('dataAllowSelfSigned')]
	public function testAllowSelfSigned(array $systemConfig, bool $debug, bool $verify): void {
		$this->givenSystemConfig($systemConfig, $debug);
		$this->expectRequest('get', 'https://login.example.org/.well-known/openid-configuration', false, $verify);
		$this->expectRequest('post', 'https://login.example.org/token', false, $verify);

		$this->helper->get('https://login.example.org/.well-known/openid-configuration');
		$this->helper->post('https://login.example.org/token', '');
	}

	public function testProviderSetupDiscoveryAllowsAnyHost(): void {
		$this->givenSystemConfig(['httpclient.allowlocalprovider' => true]);
		$this->givenProviders();
		$this->expectRequest('get', 'http://new-idp.lan/.well-known/openid-configuration', true);

		$this->assertSame('body', $this->helper->getProviderSetupDiscovery('http://new-idp.lan/.well-known/openid-configuration'));
	}

	public function testProviderSetupDiscoveryKeepsProtectionWhenOptionUnset(): void {
		$this->givenSystemConfig([]);
		$this->expectRequest('get', 'http://new-idp.lan/.well-known/openid-configuration', false);

		$this->helper->getProviderSetupDiscovery('http://new-idp.lan/.well-known/openid-configuration');
	}
}
