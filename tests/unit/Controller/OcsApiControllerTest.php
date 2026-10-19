<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OC\Files\Template\TemplateManager;
use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Controller\OcsApiController;
use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Extends \Test\TestCase (not PHPUnit's) for overwriteService(), because the
 * skeleton is copied through \OC_Util::copySkeleton(). Overwriting the template
 * manager creates the real one first, which uses the database.
 */
#[Group('DB')]
class OcsApiControllerTest extends \Test\TestCase {
	private UserMapper&MockObject $userMapper;
	private IUserManager&MockObject $userManager;
	private AuditLogger&MockObject $auditLogger;
	private OcsApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->userMapper = $this->createMock(UserMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);
		$this->overwriteService(TemplateManager::class, $this->createMock(TemplateManager::class));

		$this->controller = new OcsApiController(
			$this->createMock(IRequest::class),
			$this->createMock(IRootFolder::class),
			$this->userMapper,
			$this->userManager,
			$this->auditLogger,
		);
	}

	public static function dataCreateUser(): array {
		return [
			'new account' => [true],
			'existing account' => [false],
		];
	}

	#[DataProvider('dataCreateUser')]
	public function testCreateUser(bool $created): void {
		$backendUser = new User();
		$backendUser->setUserId('backend-uid');
		$backendUser->setDisplayName('');
		$this->userMapper->expects($this->once())
			->method('getOrCreate')
			->with(3, 'the-sub')
			->willReturnCallback(static function (int $providerId, string $sub, bool $id4me = false, ?bool &$wasCreated = null) use ($backendUser, $created): User {
				$wasCreated = $created;
				return $backendUser;
			});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('backend-uid');
		$this->userManager->method('get')->with('backend-uid')->willReturn($user);

		$this->auditLogger->expects($created ? $this->once() : $this->never())
			->method('userProvisioned')
			->with('backend-uid', 3, 'provisioning_api');

		$response = $this->controller->createUser(3, 'the-sub');
		$this->assertSame(['user_id' => 'backend-uid'], $response->getData());
	}

	public function testCreateUserThatCannotBeLoadedDoesNotLog(): void {
		$backendUser = new User();
		$backendUser->setUserId('backend-uid');
		$this->userMapper->method('getOrCreate')
			->willReturnCallback(static function (int $providerId, string $sub, bool $id4me = false, ?bool &$wasCreated = null) use ($backendUser): User {
				$wasCreated = true;
				return $backendUser;
			});
		$this->userManager->method('get')->with('backend-uid')->willReturn(null);
		$this->auditLogger->expects($this->never())->method('userProvisioned');

		$this->expectException(\RuntimeException::class);
		$this->controller->createUser(3, 'the-sub');
	}
}
