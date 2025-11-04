<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


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

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
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
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(JwkService::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		$jwkId = 'sig_key_' . $pemPrivateKeyExpiresAt;
		$signedJwtToken = $this->jwkService->createJwt($initialPayload, $sslPrivateKey, $jwkId, 'ES384');

		// check JWK
		$jwk = $this->jwkService->getJwkFromSslKey($pubKey);
		$this->assertEquals('EC', $jwk['kty']);
		$this->assertEquals('sig', $jwk['use']);
		$this->assertEquals($jwkId, $jwk['kid']);
		$this->assertEquals('P-384', $jwk['crv']);
		$this->assertEquals('ES384', $jwk['alg']);

		// check content of JWT
		$rawJwks = ['keys' => [$jwk]];
		$jwks = JWK::parseKeySet($rawJwks, 'ES384');
		$jwtPayload = JWT::decode($signedJwtToken, $jwks);
		$jwtPayloadArray = json_decode(json_encode($jwtPayload), true);
		$this->assertEquals($initialPayload, $jwtPayloadArray);

		// check header of JWT
		$jwtParts = explode('.', $signedJwtToken, 3);
		$jwtHeader = json_decode(JWT::urlsafeB64Decode($jwtParts[0]), true);
		$this->assertEquals('JWT', $jwtHeader['typ']);
		$this->assertEquals('ES384', $jwtHeader['alg']);
		$this->assertEquals($jwkId, $jwtHeader['kid']);
	}
}
