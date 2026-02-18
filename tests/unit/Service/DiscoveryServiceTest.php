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

	/**
	 * Test that fixJwksAlg filters out keys with unsupported key types.
	 * This prevents Firebase JWT from crashing on P-521 or OKP keys.
	 * See https://github.com/firebase/php-jwt/issues/561
	 */
	public function testFixJwksAlgFiltersUnsupportedKeyTypes() {
		// Build a fake JWT with RS256 alg and a known kid
		$header = json_encode(['alg' => 'RS256', 'kid' => 'rsa-key-1', 'typ' => 'JWT']);
		$payload = json_encode(['sub' => '1234']);
		$fakeJwt = rtrim(strtr(base64_encode($header), '+/', '-_'), '=')
			. '.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
			. '.fake-signature';

		// JWKS with mixed key types: RSA (matching), EC P-521, and OKP
		$jwks = [
			'keys' => [
				[
					'kty' => 'EC',
					'crv' => 'P-521',
					'kid' => 'ec-p521-key',
					'use' => 'sig',
					'x' => 'AekpBQ8ST8a8VcfVOTNl353vSrDCLL-Jmn1TZOFz5EhU',
					'y' => 'ADSmRA43Z1DSNx_RvcLI87cdL07l6jQyyBXMoxVg_l2T',
				],
				[
					'kty' => 'RSA',
					'kid' => 'rsa-key-1',
					'use' => 'sig',
					'n' => str_repeat('A', 342), // Fake 2048-bit modulus (256 bytes base64)
					'e' => 'AQAB',
				],
				[
					'kty' => 'OKP',
					'crv' => 'Ed25519',
					'kid' => 'okp-key',
					'use' => 'sig',
					'x' => 'some-value',
				],
			],
		];

		// Mock config to disable key strength validation (we use fake key material)
		$this->config->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn(['validate_jwk_strength' => false]);

		$result = $this->discoveryService->fixJwksAlg($jwks, $fakeJwt);

		// Only the RSA key should remain
		Assert::assertCount(1, $result['keys']);
		Assert::assertEquals('RSA', $result['keys'][0]['kty']);
		Assert::assertEquals('rsa-key-1', $result['keys'][0]['kid']);
		Assert::assertEquals('RS256', $result['keys'][0]['alg']);
	}

	/**
	 * Test that fixJwksAlg works with EC keys when JWT uses ES256.
	 */
	public function testFixJwksAlgKeepsCompatibleEcKeys() {
		$header = json_encode(['alg' => 'ES256', 'kid' => 'ec-key-1', 'typ' => 'JWT']);
		$payload = json_encode(['sub' => '1234']);
		$fakeJwt = rtrim(strtr(base64_encode($header), '+/', '-_'), '=')
			. '.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
			. '.fake-signature';

		$jwks = [
			'keys' => [
				[
					'kty' => 'RSA',
					'kid' => 'rsa-key-1',
					'use' => 'sig',
					'n' => str_repeat('A', 342),
					'e' => 'AQAB',
				],
				[
					'kty' => 'EC',
					'crv' => 'P-256',
					'kid' => 'ec-key-1',
					'use' => 'sig',
					'x' => 'AekpBQ8ST8a8VcfVOTNl353vSrDCLL-Jmn1TZOFz5EhU',
					'y' => 'ADSmRA43Z1DSNx_RvcLI87cdL07l6jQyyBXMoxVg_l2T',
				],
				[
					'kty' => 'EC',
					'crv' => 'P-521',
					'kid' => 'ec-p521-key',
					'use' => 'sig',
					'x' => 'AekpBQ8ST8a8VcfVOTNl353vSrDCLL-Jmn1TZOFz5EhU',
					'y' => 'ADSmRA43Z1DSNx_RvcLI87cdL07l6jQyyBXMoxVg_l2T',
				],
			],
		];

		$this->config->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn(['validate_jwk_strength' => false]);

		$result = $this->discoveryService->fixJwksAlg($jwks, $fakeJwt);

		// Both EC keys should remain (same kty), RSA filtered out
		Assert::assertCount(2, $result['keys']);
		Assert::assertEquals('EC', $result['keys'][0]['kty']);
		Assert::assertEquals('ec-key-1', $result['keys'][0]['kid']);
		Assert::assertEquals('EC', $result['keys'][1]['kty']);
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
