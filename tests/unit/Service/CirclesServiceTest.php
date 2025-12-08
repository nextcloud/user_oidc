<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Service\CirclesService;
use OCP\App\IAppManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CirclesServiceTest extends TestCase {

	/** @var CirclesService */
	private $circlesService;

	/** @var IAppManager | MockObject */
	private $appManager;

	/** @var LoggerInterface | MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->circlesService = new CirclesService(
			$this->appManager,
			$this->logger,
		);
	}

	/**
	 * Test isCirclesEnabled returns true when Circles app is enabled
	 */
	public function testIsCirclesEnabledReturnsTrue(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(true);

		$result = $this->circlesService->isCirclesEnabled();

		$this->assertTrue($result);
	}

	/**
	 * Test isCirclesEnabled returns false when Circles app is disabled
	 */
	public function testIsCirclesEnabledReturnsFalse(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(false);

		$result = $this->circlesService->isCirclesEnabled();

		$this->assertFalse($result);
	}

	/**
	 * Test getOrCreateCircle returns null when Circles is not enabled
	 */
	public function testGetOrCreateCircleReturnsNullWhenCirclesDisabled(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(false);

		$result = $this->circlesService->getOrCreateCircle('org-123', 'Engineering');

		$this->assertNull($result);
	}

	/**
	 * Test addMember returns false when Circles is not enabled
	 */
	public function testAddMemberReturnsFalseWhenCirclesDisabled(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(false);

		$user = $this->createMock(IUser::class);
		$circle = new \stdClass();

		$result = $this->circlesService->addMember($circle, $user);

		$this->assertFalse($result);
	}

	/**
	 * Test removeMember returns false when Circles is not enabled
	 */
	public function testRemoveMemberReturnsFalseWhenCirclesDisabled(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(false);

		$user = $this->createMock(IUser::class);
		$circle = new \stdClass();

		$result = $this->circlesService->removeMember($circle, $user);

		$this->assertFalse($result);
	}

	/**
	 * Test getUserCircles returns empty array when Circles is not enabled
	 */
	public function testGetUserCirclesReturnsEmptyWhenCirclesDisabled(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(false);

		$user = $this->createMock(IUser::class);

		$result = $this->circlesService->getUserCircles($user);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test getCircle returns null when Circles is not enabled
	 */
	public function testGetCircleReturnsNullWhenCirclesDisabled(): void {
		$this->appManager
			->method('isEnabledForUser')
			->with('circles')
			->willReturn(false);

		$result = $this->circlesService->getCircle('org-123');

		$this->assertNull($result);
	}
}
