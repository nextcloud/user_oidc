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
			->willReturnCallback(function (string $key, string $value, bool $lazy = false) {
				$this->appConfigStrings[$key] = $value;
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

	public function testJwksContainsCurrentAndNextSignatureKeys(): void {
		$jwks = $this->jwkService->getJwks();

		$this->assertCount(3, $jwks);
		$this->assertSame('sig', $jwks[0]['use']);
		$this->assertSame('sig', $jwks[1]['use']);
		$this->assertSame('enc', $jwks[2]['use']);
		$this->assertNotSame($jwks[0]['kid'], $jwks[1]['kid']);
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
}
