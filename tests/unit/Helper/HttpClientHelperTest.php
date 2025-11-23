<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Helper\HttpClientHelper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpClientHelperTest extends TestCase {

	/**
	 * @var IClientService|MockObject
	 */
	private $clientService;
	/**
	 * @var IConfig|MockObject
	 */
	private $config;
	/**
	 * @var IClient|MockObject
	 */
	private $client;
	/**
	 * @var HttpClientHelper
	 */
	private $httpClientHelper;

	public function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->client = $this->createMock(IClient::class);
		$this->clientService->expects(self::any())
			->method('newClient')
			->willReturn($this->client);
		$this->httpClientHelper = new HttpClientHelper($this->clientService, $this->config);
	}

	public function testGetWithTlsVerifyFromOptions() {
		$response = $this->createMock(IResponse::class);
		$response->expects(self::once())
			->method('getBody')
			->willReturn('response body');

		$this->config->expects(self::once())
			->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn([]);

		$this->config->expects(self::once())
			->method('getSystemValueBool')
			->with('debug', false)
			->willReturn(false);

		$this->client->expects(self::once())
			->method('get')
			->with('https://example.com', ['verify' => false])
			->willReturn($response);

		$result = $this->httpClientHelper->get('https://example.com', [], ['verify' => false]);
		Assert::assertEquals('response body', $result);
	}

	public function testGetWithGlobalTlsConfig() {
		$response = $this->createMock(IResponse::class);
		$response->expects(self::once())
			->method('getBody')
			->willReturn('response body');

		$this->config->expects(self::once())
			->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn(['httpclient.allowselfsigned' => true]);

		$this->config->expects(self::once())
			->method('getSystemValueBool')
			->with('debug', false)
			->willReturn(false);

		$this->client->expects(self::once())
			->method('get')
			->with('https://example.com', ['verify' => false])
			->willReturn($response);

		$result = $this->httpClientHelper->get('https://example.com');
		Assert::assertEquals('response body', $result);
	}

	public function testPostWithOptions() {
		$response = $this->createMock(IResponse::class);
		$response->expects(self::once())
			->method('getBody')
			->willReturn('response body');

		$this->config->expects(self::once())
			->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn([]);

		$this->client->expects(self::once())
			->method('post')
			->with('https://example.com', [
				'headers' => ['Authorization' => 'Bearer token'],
				'body' => ['key' => 'value'],
				'verify' => false,
			])
			->willReturn($response);

		$result = $this->httpClientHelper->postWithOptions(
			'https://example.com',
			['key' => 'value'],
			['Authorization' => 'Bearer token'],
			['verify' => false]
		);
		Assert::assertEquals('response body', $result);
	}

	public function testPostWithOptionsGlobalTlsConfig() {
		$response = $this->createMock(IResponse::class);
		$response->expects(self::once())
			->method('getBody')
			->willReturn('response body');

		$this->config->expects(self::once())
			->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn(['httpclient.allowselfsigned' => true]);

		$this->client->expects(self::once())
			->method('post')
			->with('https://example.com', [
				'headers' => [],
				'body' => ['key' => 'value'],
				'verify' => false,
			])
			->willReturn($response);

		$result = $this->httpClientHelper->postWithOptions(
			'https://example.com',
			['key' => 'value']
		);
		Assert::assertEquals('response body', $result);
	}

	public function testPostWithOptionsTlsVerifyOverride() {
		$response = $this->createMock(IResponse::class);
		$response->expects(self::once())
			->method('getBody')
			->willReturn('response body');

		$this->config->expects(self::once())
			->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn(['httpclient.allowselfsigned' => true]);

		// Even though global config says allow self-signed, the explicit verify=true should override
		$this->client->expects(self::once())
			->method('post')
			->with('https://example.com', [
				'headers' => [],
				'body' => ['key' => 'value'],
				'verify' => true,
			])
			->willReturn($response);

		$result = $this->httpClientHelper->postWithOptions(
			'https://example.com',
			['key' => 'value'],
			[],
			['verify' => true]
		);
		Assert::assertEquals('response body', $result);
	}
}

