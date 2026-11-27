<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Controller\OcsApiController;
use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OcsApiControllerTest extends TestCase {
	private UserMapper&MockObject $userMapper;
	private IUserManager&MockObject $userManager;
	private IEventDispatcher&MockObject $eventDispatcher;
	private IUser&MockObject $user;
	private User $backendUser;
	private OcsApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->backendUser = new User();
		$this->backendUser->setUserId('uid');
		$this->backendUser->setDisplayName('');

		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('uid');

		$this->userMapper = $this->createMock(UserMapper::class);
		$this->userMapper->method('getOrCreate')->willReturn($this->backendUser);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->with('uid')->willReturn($this->user);

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->createMock(Folder::class));

		$this->controller = new OcsApiController(
			$this->createMock(IRequest::class),
			$root,
			$this->userMapper,
			$this->userManager,
			$this->createMock(AuditLogger::class),
			$this->eventDispatcher,
		);
	}

	public function testChangedDisplayNameDispatchesUserChangedEvent(): void {
		$this->backendUser->setDisplayName('uid');

		$this->userMapper->expects(self::once())->method('update')->with($this->backendUser);
		$this->eventDispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(function ($event): bool {
				return $event instanceof UserChangedEvent
					&& $event->getUser() === $this->user
					&& $event->getFeature() === 'displayName'
					&& $event->getValue() === 'Jane Doe'
					&& $event->getOldValue() === 'uid';
			}));

		$this->controller->createUser(1, 'uid', 'Jane Doe');

		self::assertSame('Jane Doe', $this->backendUser->getDisplayName());
	}

	public function testUnchangedDisplayNameDispatchesNoEvent(): void {
		$this->backendUser->setDisplayName('Jane Doe');

		$this->userMapper->expects(self::never())->method('update');
		$this->eventDispatcher->expects(self::never())->method('dispatchTyped');

		$this->controller->createUser(1, 'uid', 'Jane Doe');
	}

	public function testMissingDisplayNameDispatchesNoEvent(): void {
		$this->userMapper->expects(self::never())->method('update');
		$this->eventDispatcher->expects(self::never())->method('dispatchTyped');

		$this->controller->createUser(1, 'uid');
	}
}
