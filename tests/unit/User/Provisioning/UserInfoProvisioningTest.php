<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\User\Provisioning\UserInfoProvisioning;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserInfoProvisioningTest extends TestCase {

	/** @var OIDCService | MockObject */
	private $oidcService;

	/** @var ProvisioningService | MockObject */
	private $provisioningService;

	/** @var LoggerInterface | MockObject */
	private $logger;

	/** @var UserInfoProvisioning */
	private $userInfoProvisioning;

	/** @var Provider */
	private $provider;

	public function setUp(): void {
		parent::setUp();

		$this->oidcService = $this->createMock(OIDCService::class);
		$this->provisioningService = $this->createMock(ProvisioningService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new Provider();
		$this->provider->setId(1);

		$this->userInfoProvisioning = new UserInfoProvisioning(
			$this->oidcService,
			$this->provisioningService,
			$this->logger,
		);
	}

	public function testProvisionUserUsesTheUserInfoPayload(): void {
		$userInfo = ['sub' => 'user1', 'email' => 'user1@example.com'];
		$this->oidcService->expects($this->once())
			->method('userinfo')
			->with($this->provider, 'opaque-token')
			->willReturn($userInfo);

		$user = $this->createMock(IUser::class);
		$this->provisioningService->expects($this->once())
			->method('provisionUser')
			->with(
				'user1',
				1,
				$this->callback(static function (object $payload): bool {
					return $payload->sub === 'user1' && $payload->email === 'user1@example.com';
				}),
				null,
			)
			->willReturn(['user' => $user]);

		$this->assertSame($user, $this->userInfoProvisioning->provisionUser($this->provider, 'user1', 'opaque-token', null));
	}

	public function testProvisionUserReturnsNullWhenUserInfoIsUnavailable(): void {
		$this->oidcService->expects($this->once())
			->method('userinfo')
			->willThrowException(new RuntimeException('userinfo endpoint is down'));

		$this->logger->expects($this->once())
			->method('error');
		$this->provisioningService->expects($this->never())
			->method('provisionUser');

		$this->assertNull($this->userInfoProvisioning->provisionUser($this->provider, 'user1', 'opaque-token', null));
	}
}
