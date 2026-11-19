<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Dav\BearerBackend;
use OCA\UserOIDC\Dav\DavRequestUser;
use OCA\UserOIDC\User\Backend;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * validateBearerToken() is protected. The subclass records the filesystem call
 * so the test does not need OC_Util::setupFS().
 */
class BearerBackendBindTest extends TestCase {
	public function testAResolvedUserIsBoundAndTheFilesystemIsSetUp(): void {
		if (!class_exists(\Sabre\DAV\Auth\Backend\AbstractBearer::class)) {
			$this->markTestSkipped('Sabre is not available. Set NEXTCLOUD_3RDPARTY to the server 3rdparty directory.');
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice@example.test');
		$userBackend = $this->createMock(Backend::class);
		$userBackend->expects($this->once())->method('resolveBearerUserId')->with(false)->willReturn('alice@example.test');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alice@example.test')->willReturn($user);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->expects($this->never())->method('setVolatileActiveUser');
		$userSession->expects($this->never())->method('setUser');
		$davRequestUser = $this->createMock(DavRequestUser::class);
		$davRequestUser->expects($this->once())->method('bind')->with($userSession, $user)->willReturn(true);

		$backend = new class($userBackend, $userSession, $userManager, $davRequestUser) extends BearerBackend {
			public int $filesystemSetups = 0;

			protected function setupFilesystem(string $uid): void {
				$this->filesystemSetups++;
			}
		};

		$this->assertSame('principals/users/alice@example.test', $this->validate($backend, 'access-token'));
		$this->assertSame(1, $backend->filesystemSetups);
	}

	public function testAnEmptyTokenDoesNotResolveAUser(): void {
		if (!class_exists(\Sabre\DAV\Auth\Backend\AbstractBearer::class)) {
			$this->markTestSkipped('Sabre is not available. Set NEXTCLOUD_3RDPARTY to the server 3rdparty directory.');
		}

		$userBackend = $this->createMock(Backend::class);
		$userBackend->expects($this->never())->method('resolveBearerUserId');
		$davRequestUser = $this->createMock(DavRequestUser::class);
		$davRequestUser->expects($this->never())->method('bind');
		$backend = new BearerBackend(
			$userBackend,
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$davRequestUser,
		);

		$this->assertFalse($this->validate($backend, ''));
		$this->assertFalse($this->validate($backend, false));
	}

	public function testAMissingNextcloudUserDoesNotBind(): void {
		if (!class_exists(\Sabre\DAV\Auth\Backend\AbstractBearer::class)) {
			$this->markTestSkipped('Sabre is not available. Set NEXTCLOUD_3RDPARTY to the server 3rdparty directory.');
		}

		$userBackend = $this->createMock(Backend::class);
		$userBackend->method('resolveBearerUserId')->willReturn('ghost');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('ghost')->willReturn(null);
		$davRequestUser = $this->createMock(DavRequestUser::class);
		$davRequestUser->expects($this->never())->method('bind');
		$backend = new class($userBackend, $this->createMock(IUserSession::class), $userManager, $davRequestUser) extends BearerBackend {
			public int $filesystemSetups = 0;

			protected function setupFilesystem(string $uid): void {
				$this->filesystemSetups++;
			}
		};

		$this->assertFalse($this->validate($backend, 'access-token'));
		$this->assertSame(0, $backend->filesystemSetups);
	}

	private function validate(object $backend, mixed $token): mixed {
		$method = new ReflectionMethod(BearerBackend::class, 'validateBearerToken');
		return $method->invoke($backend, $token);
	}
}
