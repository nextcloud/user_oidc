<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Service\JweService;
use OCA\UserOIDC\Service\JwkService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class JweServiceTest extends TestCase {

	/** @var IAppConfig|MockObject */
	private $appConfig;
	/** @var JwkService|MockObject */
	private $jwkService;
	/** @var JweService|MockObject */
	private $jweService;
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
		$this->jweService = new JweService(
			$this->jwkService,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testJweEncryptionDecryption() {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey();
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		$encPublicJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true);
		$encPrivJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true, includePrivateKey: true);

		$inputPayloadArray = [
			'iat' => time(),
			'nbf' => time(),
			'exp' => time() + 3600,
			'iss' => 'My service',
			'aud' => 'Your application',
		];
		$inputPayload = json_encode($inputPayloadArray);

		$serializedJweToken = $this->jweService->createSerializedJweWithKey($inputPayload, $encPublicJwk);
		$decryptedJweString = $this->jweService->decryptSerializedJweWithKey($serializedJweToken, $encPrivJwk);

		$outputPayloadArray = json_decode($decryptedJweString, true);
		$this->assertEquals($inputPayloadArray, $outputPayloadArray);
	}

	public function testDecryptWithCurrentKey(): void {
		$serializedJwe = $this->jweService->createSerializedJwe('payload');

		$this->assertSame('payload', $this->jweService->decryptSerializedJwe($serializedJwe));
	}

	public function testDecryptWithPrepublishedNextKey(): void {
		$nextJwk = $this->jwkService->getJwks()[3];
		$serializedJwe = $this->jweService->createSerializedJweWithKey('payload', $nextJwk);

		$this->assertSame('payload', $this->jweService->decryptSerializedJwe($serializedJwe));
	}

	public function testDecryptWithoutKidHeader(): void {
		$nextJwk = $this->jwkService->getJwks()[3];
		unset($nextJwk['kid']);
		$serializedJwe = $this->jweService->createSerializedJweWithKey('payload', $nextJwk);

		$this->assertSame('payload', $this->jweService->decryptSerializedJwe($serializedJwe));
	}

	public function testDecryptWithKeyRetiredAfterLongIdlePeriod(): void {
		// keys published 12 hours ago and not accessed since then
		$oldCurrentKey = $this->jwkService->generatePemPrivateKey();
		$oldCurrentCreatedAt = time() - 12 * 60 * 60;
		$this->appConfigStrings[JwkService::PEM_ENC_KEY_SETTINGS_KEY] = $oldCurrentKey;
		$this->appConfigInts[JwkService::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY] = $oldCurrentCreatedAt;
		$this->appConfigStrings[JwkService::PEM_NEXT_ENC_KEY_SETTINGS_KEY] = $this->jwkService->generatePemPrivateKey();
		$this->appConfigInts[JwkService::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY] = $oldCurrentCreatedAt + 1;

		// the IdP encrypts with the current key from its cached JWKS
		$oldCurrentKeyDetails = openssl_pkey_get_details(openssl_pkey_get_private($oldCurrentKey));
		$oldCurrentJwk = $this->jwkService->getJwkFromSslKey($oldCurrentKeyDetails, isEncryptionKey: true, keyCreatedAt: $oldCurrentCreatedAt);
		$serializedJwe = $this->jweService->createSerializedJweWithKey('payload', $oldCurrentJwk);

		// decrypting rotates the keys, the retired one must still be usable
		$this->assertSame('payload', $this->jweService->decryptSerializedJwe($serializedJwe));
		$this->assertSame($oldCurrentKey, $this->appConfigStrings[JwkService::PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY]);
	}

	public function testDecryptWithUnknownKeyFails(): void {
		$this->jwkService->getMyEncryptionKey();
		$foreignKeyDetails = openssl_pkey_get_details(openssl_pkey_get_private($this->jwkService->generatePemPrivateKey()));
		$foreignJwk = $this->jwkService->getJwkFromSslKey($foreignKeyDetails, isEncryptionKey: true, keyCreatedAt: 42);
		$serializedJwe = $this->jweService->createSerializedJweWithKey('payload', $foreignJwk);

		$this->expectException(\Exception::class);
		$this->jweService->decryptSerializedJwe($serializedJwe);
	}
}
