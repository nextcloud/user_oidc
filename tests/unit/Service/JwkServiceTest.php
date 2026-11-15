<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\JwkService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JwkServiceTest extends TestCase {

	/** @var IAppConfig|MockObject */
	private $appConfig;
	/** @var JwkService|MockObject */
	private $jwkService;
	private array $appConfigStrings = [];
	private array $sensitiveAppConfigKeys = [];
	private array $appConfigInts = [];

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key, ?string $default = null, bool $lazy = false) {
				return $this->appConfigStrings[$key] ?? ($default ?? '');
			});
		$this->appConfig->method('getAppValueInt')
			->willReturnCallback(function (string $key, ?int $default = null, bool $lazy = false) {
				return $this->appConfigInts[$key] ?? ($default ?? 0);
			});
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function (string $key, string $value, bool $lazy = false, bool $sensitive = false) {
				$this->appConfigStrings[$key] = $value;
				if ($sensitive) {
					$this->sensitiveAppConfigKeys[$key] = true;
				}
				return true;
			});
		$this->appConfig->method('setAppValueInt')
			->willReturnCallback(function (string $key, int $value, bool $lazy = false) {
				$this->appConfigInts[$key] = $value;
				return true;
			});
		$this->jwkService = new JwkService($this->appConfig);
	}

	public function testSignatureKeyAndJwt() {
		$myPemPrivateKey = $this->jwkService->getMyPemSignatureKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pubKey = openssl_pkey_get_details($sslPrivateKey);
		$pubKeyPem = $pubKey['key'];
		$this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $pubKeyPem);
		$this->assertStringContainsString('-----END PUBLIC KEY-----', $pubKeyPem);
		$this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $myPemPrivateKey);
		$this->assertStringContainsString('-----END PRIVATE KEY-----', $myPemPrivateKey);

		$initialPayload = ['nice' => 'example'];
		$pemSignatureKeyCreatedAt = $this->appConfig->getAppValueInt(JwkService::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY, lazy: true);
		$jwkId = 'sig_key_' . $pemSignatureKeyCreatedAt;
		$signedJwtToken = $this->jwkService->createJwt($initialPayload, $sslPrivateKey, $jwkId, JwkService::PEM_SIG_KEY_ALGORITHM);

		// check JWK
		$jwk = $this->jwkService->getJwkFromSslKey($pubKey);
		$this->assertEquals('EC', $jwk['kty']);
		$this->assertEquals('sig', $jwk['use']);
		$this->assertEquals($jwkId, $jwk['kid']);
		$this->assertEquals(JwkService::PEM_SIG_KEY_CURVE, $jwk['crv']);
		$this->assertEquals(JwkService::PEM_SIG_KEY_ALGORITHM, $jwk['alg']);

		// check content of JWT
		$rawJwks = ['keys' => [$jwk]];
		$jwks = JWK::parseKeySet($rawJwks, JwkService::PEM_SIG_KEY_ALGORITHM);
		$jwtPayload = JWT::decode($signedJwtToken, $jwks);
		$jwtPayloadArray = json_decode(json_encode($jwtPayload), true);
		$this->assertEquals($initialPayload, $jwtPayloadArray);

		// check header of JWT
		$jwtParts = explode('.', $signedJwtToken, 3);
		$jwtHeader = json_decode(JWT::urlsafeB64Decode($jwtParts[0]), true);
		$this->assertEquals('JWT', $jwtHeader['typ']);
		$this->assertEquals(JwkService::PEM_SIG_KEY_ALGORITHM, $jwtHeader['alg']);
		$this->assertEquals($jwkId, $jwtHeader['kid']);
	}

	public function testEncryptionKey() {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey();
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		$encJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true);

		$pemEncryptionKeyCreatedAt = $this->appConfig->getAppValueInt(JwkService::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY, lazy: true);
		$encJwkId = 'enc_key_' . $pemEncryptionKeyCreatedAt;

		$this->assertEquals('EC', $encJwk['kty']);
		$this->assertEquals('enc', $encJwk['use']);
		$this->assertEquals($encJwkId, $encJwk['kid']);
		$this->assertEquals(JwkService::PEM_ENC_KEY_CURVE, $encJwk['crv']);
		$this->assertEquals(JwkService::PEM_ENC_KEY_ALGORITHM, $encJwk['alg']);
	}

	public function testJwksContainsCurrentAndNextKeys(): void {
		$jwks = $this->jwkService->getJwks();

		$this->assertCount(4, $jwks);
		$this->assertSame(['sig', 'sig', 'enc', 'enc'], array_column($jwks, 'use'));
		$this->assertSame('sig_key_' . $this->appConfigInts[JwkService::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY], $jwks[0]['kid']);
		$this->assertSame('sig_key_' . $this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY], $jwks[1]['kid']);
		$this->assertSame('enc_key_' . $this->appConfigInts[JwkService::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY], $jwks[2]['kid']);
		$this->assertSame('enc_key_' . $this->appConfigInts[JwkService::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY], $jwks[3]['kid']);
		$this->assertCount(4, array_unique(array_column($jwks, 'kid')));
		foreach ($jwks as $jwk) {
			$this->assertArrayNotHasKey('d', $jwk);
		}
	}

	public function testKeysAreStableBetweenCalls(): void {
		$firstJwks = $this->jwkService->getJwks();
		$secondJwks = $this->jwkService->getJwks();

		$this->assertSame($firstJwks, $secondJwks);
	}

	public function testSignatureKeyIsNotRotatedWhileNextKeyIsRecent(): void {
		$currentKey = $this->jwkService->generatePemPrivateKey();
		$nextKey = $this->jwkService->generatePemPrivateKey();
		$currentCreatedAt = time() - JwkService::PEM_SIG_KEY_EXPIRES_AFTER_SECONDS - 10;
		$nextCreatedAt = time() - 10;
		$this->appConfigStrings[JwkService::PEM_SIG_KEY_SETTINGS_KEY] = $currentKey;
		$this->appConfigInts[JwkService::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY] = $currentCreatedAt;
		$this->appConfigStrings[JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY] = $nextKey;
		$this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY] = $nextCreatedAt;

		$this->assertSame($currentKey, $this->jwkService->getMyPemSignatureKey());
		$this->assertSame($nextKey, $this->appConfigStrings[JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY]);
		$this->assertSame($nextCreatedAt, $this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY]);
	}

	public function testMissingCurrentSignatureKeyPromotesNextKey(): void {
		$nextKey = $this->jwkService->generatePemPrivateKey();
		$nextCreatedAt = time() - 10;
		$this->appConfigStrings[JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY] = $nextKey;
		$this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY] = $nextCreatedAt;

		$this->assertSame($nextKey, $this->jwkService->getMyPemSignatureKey());
		$this->assertSame($nextCreatedAt, $this->appConfigInts[JwkService::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY]);
		$this->assertNotSame($nextKey, $this->appConfigStrings[JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY]);
		$this->assertGreaterThan($nextCreatedAt, $this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY]);
	}

	public function testExpiredCurrentSignatureKeyPromotesPrepublishedNextKey(): void {
		$oldCurrentKey = $this->jwkService->generatePemPrivateKey();
		$oldNextKey = $this->jwkService->generatePemPrivateKey();
		$oldCurrentCreatedAt = time() - JwkService::PEM_SIG_KEY_EXPIRES_AFTER_SECONDS - 20;
		$oldNextCreatedAt = time() - JwkService::PEM_SIG_KEY_EXPIRES_AFTER_SECONDS - 10;

		$this->appConfigStrings[JwkService::PEM_SIG_KEY_SETTINGS_KEY] = $oldCurrentKey;
		$this->appConfigInts[JwkService::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY] = $oldCurrentCreatedAt;
		$this->appConfigStrings[JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY] = $oldNextKey;
		$this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY] = $oldNextCreatedAt;

		$provider = new Provider();
		$provider->setClientId('client-id');

		$this->jwkService->generateClientAssertion($provider, 'https://issuer.example');

		$this->assertSame($oldNextKey, $this->appConfigStrings[JwkService::PEM_SIG_KEY_SETTINGS_KEY]);
		$this->assertSame($oldNextCreatedAt, $this->appConfigInts[JwkService::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY]);
		$this->assertNotSame($oldNextKey, $this->appConfigStrings[JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY]);
		$this->assertGreaterThan($oldNextCreatedAt, $this->appConfigInts[JwkService::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY]);

		$jwks = $this->jwkService->getJwks();
		$this->assertSame('sig_key_' . $oldNextCreatedAt, $jwks[0]['kid']);
		$this->assertNotSame($jwks[0]['kid'], $jwks[1]['kid']);
	}

	public function testExpiredEncryptionKeyIsRetiredAndNextKeyPromoted(): void {
		$oldCurrentKey = $this->jwkService->generatePemPrivateKey();
		$oldNextKey = $this->jwkService->generatePemPrivateKey();
		$oldCurrentCreatedAt = time() - JwkService::PEM_ENC_KEY_EXPIRES_AFTER_SECONDS - 20;
		$oldNextCreatedAt = time() - JwkService::PEM_ENC_KEY_EXPIRES_AFTER_SECONDS - 10;
		$this->appConfigStrings[JwkService::PEM_ENC_KEY_SETTINGS_KEY] = $oldCurrentKey;
		$this->appConfigInts[JwkService::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY] = $oldCurrentCreatedAt;
		$this->appConfigStrings[JwkService::PEM_NEXT_ENC_KEY_SETTINGS_KEY] = $oldNextKey;
		$this->appConfigInts[JwkService::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY] = $oldNextCreatedAt;

		$this->assertSame($oldNextKey, $this->jwkService->getMyEncryptionKey());
		$this->assertSame($oldNextCreatedAt, $this->appConfigInts[JwkService::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY]);
		$this->assertNotSame($oldNextKey, $this->appConfigStrings[JwkService::PEM_NEXT_ENC_KEY_SETTINGS_KEY]);
		$this->assertSame($oldCurrentKey, $this->appConfigStrings[JwkService::PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY]);
		$this->assertSame($oldCurrentCreatedAt, $this->appConfigInts[JwkService::PEM_PREVIOUS_ENC_KEY_CREATED_AT_SETTINGS_KEY]);
		$this->assertGreaterThanOrEqual(time() - 5, $this->appConfigInts[JwkService::PEM_PREVIOUS_ENC_KEY_RETIRED_AT_SETTINGS_KEY]);

		// the retired key is not published anymore, but can still decrypt
		$publishedKids = array_column($this->jwkService->getJwks(), 'kid');
		$this->assertNotContains('enc_key_' . $oldCurrentCreatedAt, $publishedKids);
		$this->assertContains('enc_key_' . $oldNextCreatedAt, $publishedKids);
		$decryptionKids = array_column($this->jwkService->getDecryptionJwks(), 'kid');
		$this->assertContains('enc_key_' . $oldCurrentCreatedAt, $decryptionKids);
	}

	public function testPrivateKeysAreStoredAsSensitive(): void {
		$this->appConfigStrings[JwkService::PEM_ENC_KEY_SETTINGS_KEY] = $this->jwkService->generatePemPrivateKey();
		$this->appConfigInts[JwkService::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY] = time() - JwkService::PEM_ENC_KEY_EXPIRES_AFTER_SECONDS - 20;
		$this->appConfigStrings[JwkService::PEM_NEXT_ENC_KEY_SETTINGS_KEY] = $this->jwkService->generatePemPrivateKey();
		$this->appConfigInts[JwkService::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY] = time() - JwkService::PEM_ENC_KEY_EXPIRES_AFTER_SECONDS - 10;

		$this->jwkService->getJwks();

		$expectedKeys = [
			JwkService::PEM_SIG_KEY_SETTINGS_KEY,
			JwkService::PEM_NEXT_SIG_KEY_SETTINGS_KEY,
			JwkService::PEM_ENC_KEY_SETTINGS_KEY,
			JwkService::PEM_NEXT_ENC_KEY_SETTINGS_KEY,
			JwkService::PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY,
		];
		sort($expectedKeys);
		$sensitiveKeys = array_keys($this->sensitiveAppConfigKeys);
		sort($sensitiveKeys);
		$this->assertSame($expectedKeys, $sensitiveKeys);
	}

	public function testDecryptionJwksContainPublishedKeysWithPrivatePart(): void {
		$publishedEncKids = array_column(
			array_filter($this->jwkService->getJwks(), static fn (array $jwk) => $jwk['use'] === 'enc'),
			'kid',
		);
		$decryptionJwks = $this->jwkService->getDecryptionJwks();

		$this->assertSame(array_values($publishedEncKids), array_column($decryptionJwks, 'kid'));
		foreach ($decryptionJwks as $jwk) {
			$this->assertArrayHasKey('d', $jwk);
		}
	}

	public function testRetiredEncryptionKeyIsDroppedAfterGracePeriod(): void {
		$this->jwkService->getMyEncryptionKey();
		$previousCreatedAt = time() - 3 * 60 * 60;
		$this->appConfigStrings[JwkService::PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY] = $this->jwkService->generatePemPrivateKey();
		$this->appConfigInts[JwkService::PEM_PREVIOUS_ENC_KEY_CREATED_AT_SETTINGS_KEY] = $previousCreatedAt;

		$this->appConfigInts[JwkService::PEM_PREVIOUS_ENC_KEY_RETIRED_AT_SETTINGS_KEY] = time() - JwkService::PEM_PREVIOUS_ENC_KEY_GRACE_PERIOD_SECONDS + 60;
		$this->assertContains('enc_key_' . $previousCreatedAt, array_column($this->jwkService->getDecryptionJwks(), 'kid'));

		$this->appConfigInts[JwkService::PEM_PREVIOUS_ENC_KEY_RETIRED_AT_SETTINGS_KEY] = time() - JwkService::PEM_PREVIOUS_ENC_KEY_GRACE_PERIOD_SECONDS - 60;
		$this->assertNotContains('enc_key_' . $previousCreatedAt, array_column($this->jwkService->getDecryptionJwks(), 'kid'));
	}
}
