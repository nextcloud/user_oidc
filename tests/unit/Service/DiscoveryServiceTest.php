<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DiscoveryServiceTest extends TestCase {

	/** @var MockObject|LoggerInterface */
	private $logger;
	/** @var HttpClientHelper|MockObject */
	private $clientHelper;
	/** @var ProviderService|MockObject */
	private $providerService;
	/** @var IConfig|MockObject */
	private $config;
	/** @var ICacheFactory|MockObject */
	private $cacheFactory;
	/** @var DiscoveryService */
	private $discoveryService;

	public function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->clientHelper = $this->createMock(HttpClientHelper::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->discoveryService = new DiscoveryService($this->logger, $this->clientHelper, $this->providerService, $this->config, $this->cacheFactory);
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
}
