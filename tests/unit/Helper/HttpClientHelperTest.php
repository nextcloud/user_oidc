<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Helper\HttpClientHelper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpClientHelperTest extends TestCase {

	/** @var IClientService|MockObject */
	private $clientService;
	/** @var IClient|MockObject */
	private $client;
	/** @var IConfig|MockObject */
	private $config;
	private HttpClientHelper $helper;

	public function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(IClient::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->config = $this->createMock(IConfig::class);
		$this->helper = new HttpClientHelper($this->clientService, $this->config);
	}

	private function mockOidcConfig(array $oidcConfig): void {
		$this->config->method('getSystemValueBool')->with('debug', false)->willReturn(false);
		$this->config->method('getSystemValue')->with('user_oidc', [])->willReturn($oidcConfig);
	}

	private function mockResponse(): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('body');
		return $response;
	}

	public static function allowLocalProvider(): array {
		return [
			'not set' => [[], false],
			'true' => [['httpclient.allowlocal' => true], true],
			'string true' => [['httpclient.allowlocal' => 'true'], true],
			'1' => [['httpclient.allowlocal' => 1], true],
			'false' => [['httpclient.allowlocal' => false], false],
			'string false' => [['httpclient.allowlocal' => 'false'], false],
			'0' => [['httpclient.allowlocal' => 0], false],
			'string 0' => [['httpclient.allowlocal' => '0'], false],
		];
	}

	/**
	 * @dataProvider allowLocalProvider
	 */
	public function testGetAllowLocal(array $oidcConfig, bool $expectAllowLocal): void {
		$this->mockOidcConfig($oidcConfig);
		$this->client->expects($this->once())
			->method('get')
			->with('https://idp.local/.well-known/openid-configuration', $this->callback(function (array $options) use ($expectAllowLocal) {
				$this->assertSame($expectAllowLocal, $options['nextcloud']['allow_local_address'] ?? false);
				$this->assertArrayNotHasKey('verify', $options);
				return true;
			}))
			->willReturn($this->mockResponse());

		$this->assertSame('body', $this->helper->get('https://idp.local/.well-known/openid-configuration'));
	}

	/**
	 * @dataProvider allowLocalProvider
	 */
	public function testPostAllowLocal(array $oidcConfig, bool $expectAllowLocal): void {
		$this->mockOidcConfig($oidcConfig);
		$this->client->expects($this->once())
			->method('post')
			->with('https://idp.local/token', $this->callback(function (array $options) use ($expectAllowLocal) {
				$this->assertSame($expectAllowLocal, $options['nextcloud']['allow_local_address'] ?? false);
				$this->assertSame(['Accept' => 'application/json'], $options['headers']);
				$this->assertSame('a=b', $options['body']);
				return true;
			}))
			->willReturn($this->mockResponse());

		$this->assertSame('body', $this->helper->post('https://idp.local/token', 'a=b', ['Accept' => 'application/json']));
	}

	public function testAllowLocalAndSelfSignedCombined(): void {
		$this->mockOidcConfig([
			'httpclient.allowlocal' => true,
			'httpclient.allowselfsigned' => true,
		]);
		$this->client->expects($this->once())
			->method('get')
			->with('https://idp.local', [
				'verify' => false,
				'nextcloud' => ['allow_local_address' => true],
			])
			->willReturn($this->mockResponse());

		$this->helper->get('https://idp.local');
	}
}
