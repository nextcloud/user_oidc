<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\Vendor\Firebase\JWT\Key;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OIDCServiceTest extends TestCase {

	private DiscoveryService&MockObject $discoveryService;
	private HttpClientHelper&MockObject $clientHelper;
	private Provider&MockObject $provider;
	private OIDCService $oidcService;

	public function setUp(): void {
		parent::setUp();
		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->clientHelper = $this->createMock(HttpClientHelper::class);
		$this->provider = $this->createMock(Provider::class);
		$this->oidcService = new OIDCService(
			$this->discoveryService,
			$this->createMock(LoggerInterface::class),
			$this->clientHelper,
			$this->createMock(ICrypto::class),
		);

		$this->discoveryService->method('obtainDiscovery')
			->willReturn(['userinfo_endpoint' => 'https://idp.example.org/userinfo']);
	}

	private static function createRsaKeyPair(): array {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $privateKey);
		return [$privateKey, openssl_pkey_get_details($key)['key']];
	}

	public function testUserinfoJsonResponse(): void {
		$this->clientHelper->method('get')
			->willReturn(json_encode(['sub' => 'alice', 'email' => 'alice@example.org']));
		$this->discoveryService->expects($this->never())->method('obtainJWK');

		$this->assertSame(
			['sub' => 'alice', 'email' => 'alice@example.org'],
			$this->oidcService->userinfo($this->provider, 'access-token'),
		);
	}

	public function testUserinfoSignedJwtResponse(): void {
		[$privateKey, $publicKey] = self::createRsaKeyPair();
		$jwt = JWT::encode(['sub' => 'alice', 'email' => 'alice@example.org'], $privateKey, 'RS256', 'key1');

		$this->clientHelper->method('get')->willReturn($jwt);
		$this->discoveryService->expects($this->once())
			->method('obtainJWK')
			->with($this->provider, $jwt)
			->willReturn(['key1' => new Key($publicKey, 'RS256')]);

		$this->assertSame(
			['sub' => 'alice', 'email' => 'alice@example.org'],
			$this->oidcService->userinfo($this->provider, 'access-token'),
		);
	}

	public function testUserinfoJwtWithInvalidSignature(): void {
		[, $publicKey] = self::createRsaKeyPair();
		[$otherPrivateKey] = self::createRsaKeyPair();
		$jwt = JWT::encode(['sub' => 'mallory'], $otherPrivateKey, 'RS256', 'key1');

		$this->clientHelper->method('get')->willReturn($jwt);
		$this->discoveryService->method('obtainJWK')
			->willReturn(['key1' => new Key($publicKey, 'RS256')]);

		$this->assertSame([], $this->oidcService->userinfo($this->provider, 'access-token'));
	}
}
