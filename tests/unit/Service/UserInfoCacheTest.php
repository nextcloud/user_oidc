<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\OIDCService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserInfoCacheTest extends TestCase {
	private HttpClientHelper&MockObject $client;
	private OIDCService $service;
	private Provider $provider;

	/** @var array<string, mixed>|null */
	private ?array $stored = null;

	protected function setUp(): void {
		parent::setUp();
		$discovery = $this->createMock(DiscoveryService::class);
		$discovery->method('obtainDiscovery')->willReturn([
			'userinfo_endpoint' => 'https://idp.example.test/userinfo',
		]);
		$this->client = $this->createMock(HttpClientHelper::class);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (): ?array => $this->stored);
		$cache->method('set')->willReturnCallback(function (string $key, array $value, int $ttl): void {
			$this->assertSame(OIDCService::USERINFO_CACHE_TTL_SECONDS, $ttl);
			$this->assertNotSame('', $key);
			$this->stored = $value;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')->with('user_oidc-userinfo')->willReturn($cache);

		$this->service = new OIDCService(
			$discovery,
			$this->createMock(LoggerInterface::class),
			$this->client,
			$this->createMock(ICrypto::class),
			$factory,
		);
		$this->provider = new Provider();
		$this->provider->setId(7);
	}

	public function testARepeatedBearerReusesTheCachedUserInfo(): void {
		$this->client->expects($this->once())->method('get')->willReturn(
			json_encode(['sub' => 'alice', 'email' => 'alice@example.test'], JSON_THROW_ON_ERROR),
		);

		$first = $this->service->userinfo($this->provider, 'access-token');
		$second = $this->service->userinfo($this->provider, 'access-token');

		$this->assertSame(['sub' => 'alice', 'email' => 'alice@example.test'], $first);
		$this->assertSame($first, $second);
	}

	public function testAFailedUserInfoIsNotCached(): void {
		$this->client->expects($this->exactly(2))->method('get')->willThrowException(new RuntimeException('down'));

		$this->assertSame([], $this->service->userinfo($this->provider, 'access-token'));
		$this->assertSame([], $this->service->userinfo($this->provider, 'access-token'));
		$this->assertNull($this->stored);
	}
}
