<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DiscoveryServiceTest extends TestCase {

	/**
	 * @var MockObject|LoggerInterface
	 */
	private $logger;
	/**
	 * @var HttpClientHelper|MockObject
	 */
	private $clientHelper;
	/**
	 * @var ProviderService|MockObject
	 */
	private $providerService;
	/**
	 * @var ICacheFactory|MockObject
	 */
	private $cacheFactory;
	/**
	 * @var ICache|MockObject
	 */
	private $cache;
	/**
	 * @var DiscoveryService
	 */
	private $discoveryService;

	public function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->clientHelper = $this->createMock(HttpClientHelper::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::once())
			->method('createDistributed')
			->with('junovy_user_oidc')
			->willReturn($this->cache);
		$this->discoveryService = new DiscoveryService($this->logger, $this->clientHelper, $this->providerService, $this->cacheFactory);
	}

	public function testBuildAuthorizationUrl() {
		$xss1 = '\'"http-equiv=><svg/onload=alert(1)>';
		$cleanedXss1 = '&#039;&quot;http-equiv=&gt;&lt;svg/onload=alert(1)&gt;';
		$cleanAuthorizationEndpoint = 'https://test.org:9999/path1/path2';
		$stringQueryParams = 'param1=value1&param2=value2';
		$extraParams = [
			'extraParam1' => 'extraValue1',
			'extraParam2' => 'extraValue2',
		];
		$stringExtraParams = 'extraParam1=extraValue1&extraParam2=extraValue2';

		$extraParamsWithXssValue = [
			'extraParam1' => $xss1,
		];
		$extraParamsWithXssKey = [
			$xss1 => 'extraValue1',
		];

		$testValues = [
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => [],
				'expected_result' => $cleanAuthorizationEndpoint,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . $xss1,
				'extra_params' => [],
				'expected_result' => $cleanAuthorizationEndpoint . $cleanedXss1,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => [],
				'expected_result' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => $extraParams,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . $stringExtraParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => $extraParams,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . $stringExtraParams . '&' . $stringQueryParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => $extraParamsWithXssKey,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . urlencode($xss1) . '=extraValue1',
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => $extraParamsWithXssValue,
				'expected_result' => $cleanAuthorizationEndpoint . '?extraParam1=' . urlencode($xss1),
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => $extraParamsWithXssKey,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . urlencode($xss1) . '=extraValue1' . '&' . $stringQueryParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => $extraParamsWithXssValue,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . 'extraParam1=' . urlencode($xss1) . '&' . $stringQueryParams,
			],
		];

		foreach ($testValues as $test) {
			Assert::assertEquals(
				$test['expected_result'],
				$this->discoveryService->buildAuthorizationUrl($test['authorization_endpoint'], $test['extra_params'])
			);
		}
	}

	public function testObtainDiscoveryWithUrlOverrides() {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setDiscoveryEndpoint('https://example.com/.well-known/openid-configuration');

		$discoveryResponse = json_encode([
			'issuer' => 'https://example.com',
			'authorization_endpoint' => 'https://example.com/auth',
			'token_endpoint' => 'https://external.example.com/token',
			'jwks_uri' => 'https://external.example.com/jwks',
			'userinfo_endpoint' => 'https://external.example.com/userinfo',
		]);

		// Mock cache to return null (cache miss)
		$this->cache->expects(self::once())
			->method('get')
			->willReturn(null);

		// Mock HTTP client to return discovery response
		$this->clientHelper->expects(self::once())
			->method('get')
			->with('https://example.com/.well-known/openid-configuration', [], ['verify' => true])
			->willReturn($discoveryResponse);

		// Mock cache set
		$this->cache->expects(self::once())
			->method('set')
			->willReturn(true);

		// Mock provider service to return cache time and TLS verify
		$this->providerService->expects(self::exactly(2))
			->method('getConfigValue')
			->willReturnMap([
				[1, ProviderService::SETTING_WELL_KNOWN_CACHING_TIME, 3600, 3600],
				[1, ProviderService::SETTING_TLS_VERIFY, true, true],
			]);

		// Mock provider service to return URL overrides
		$this->providerService->expects(self::exactly(3))
			->method('getSetting')
			->willReturnMap([
				[1, ProviderService::SETTING_OVERRIDE_JWKS_URI, '', 'http://internal.example.com/jwks'],
				[1, ProviderService::SETTING_OVERRIDE_TOKEN_ENDPOINT, '', 'http://internal.example.com/token'],
				[1, ProviderService::SETTING_OVERRIDE_USERINFO_ENDPOINT, '', 'http://internal.example.com/userinfo'],
			]);

		$result = $this->discoveryService->obtainDiscovery($provider);

		// Verify that overrides were applied
		Assert::assertEquals('http://internal.example.com/jwks', $result['jwks_uri']);
		Assert::assertEquals('http://internal.example.com/token', $result['token_endpoint']);
		Assert::assertEquals('http://internal.example.com/userinfo', $result['userinfo_endpoint']);
		// Verify original values are preserved
		Assert::assertEquals('https://example.com', $result['issuer']);
		Assert::assertEquals('https://example.com/auth', $result['authorization_endpoint']);
	}

	public function testObtainDiscoveryWithoutUrlOverrides() {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setDiscoveryEndpoint('https://example.com/.well-known/openid-configuration');

		$discoveryResponse = json_encode([
			'issuer' => 'https://example.com',
			'authorization_endpoint' => 'https://example.com/auth',
			'token_endpoint' => 'https://example.com/token',
			'jwks_uri' => 'https://example.com/jwks',
			'userinfo_endpoint' => 'https://example.com/userinfo',
		]);

		// Mock cache to return null (cache miss)
		$this->cache->expects(self::once())
			->method('get')
			->willReturn(null);

		// Mock HTTP client to return discovery response
		$this->clientHelper->expects(self::once())
			->method('get')
			->with('https://example.com/.well-known/openid-configuration', [], ['verify' => true])
			->willReturn($discoveryResponse);

		// Mock cache set
		$this->cache->expects(self::once())
			->method('set')
			->willReturn(true);

		// Mock provider service to return cache time and TLS verify, no overrides
		$this->providerService->expects(self::exactly(2))
			->method('getConfigValue')
			->willReturnMap([
				[1, ProviderService::SETTING_WELL_KNOWN_CACHING_TIME, 3600, 3600],
				[1, ProviderService::SETTING_TLS_VERIFY, true, true],
			]);

		$this->providerService->expects(self::exactly(3))
			->method('getSetting')
			->willReturn('');

		$result = $this->discoveryService->obtainDiscovery($provider);

		// Verify that original values are preserved
		Assert::assertEquals('https://example.com/jwks', $result['jwks_uri']);
		Assert::assertEquals('https://example.com/token', $result['token_endpoint']);
		Assert::assertEquals('https://example.com/userinfo', $result['userinfo_endpoint']);
	}

	public function testObtainDiscoveryWithCacheTimeZero() {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setDiscoveryEndpoint('https://example.com/.well-known/openid-configuration');

		$discoveryResponse = json_encode([
			'issuer' => 'https://example.com',
			'authorization_endpoint' => 'https://example.com/auth',
			'token_endpoint' => 'https://example.com/token',
			'jwks_uri' => 'https://example.com/jwks',
		]);

		// Mock cache to return null (cache miss)
		$this->cache->expects(self::once())
			->method('get')
			->willReturn(null);

		// Mock HTTP client to return discovery response
		$this->clientHelper->expects(self::once())
			->method('get')
			->willReturn($discoveryResponse);

		// Mock provider service to return cache time of 0 (disable caching) and TLS verify
		$this->providerService->expects(self::exactly(2))
			->method('getConfigValue')
			->willReturnMap([
				[1, ProviderService::SETTING_WELL_KNOWN_CACHING_TIME, 3600, 0],
				[1, ProviderService::SETTING_TLS_VERIFY, true, true],
			]);

		$this->providerService->expects(self::exactly(3))
			->method('getSetting')
			->willReturn('');

		// Cache should not be set when cache time is 0
		$this->cache->expects(self::never())
			->method('set');

		$result = $this->discoveryService->obtainDiscovery($provider);
		Assert::assertIsArray($result);
	}
}
