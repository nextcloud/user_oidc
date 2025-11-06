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

class JweServiceTest extends TestCase {

	/** @var IAppConfig|MockObject */
	private $appConfig;
	/** @var JwkService|MockObject */
	private $jwkService;
	/** @var JweService|MockObject */
	private $jweService;

	public function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->jwkService = new JwkService($this->appConfig);
		$this->jweService = new JweService($this->jwkService);
	}

	public function testJweEncryptionDecryption() {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey(true);
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

		$serializedJweToken = $this->jweService->createSerializedJwe($inputPayloadArray, $encPublicJwk);
		$decryptedJweString = $this->jweService->decryptSerializedJwe($serializedJweToken, $encPrivJwk);

		$outputPayloadArray = json_decode($decryptedJweString, true);
		$this->assertEquals($inputPayloadArray, $outputPayloadArray);
	}
}
