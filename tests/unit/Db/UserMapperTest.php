<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\LocalIdService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserMapperTest extends TestCase {

	/** @var LocalIdService|MockObject */
	private $idService;

	/** @var IDBConnection|MockObject */
	private $db;

	/** @var UserMapper|MockObject */
	private $userMapper;

	/** @var Iconfig|MockObject */
	private $config;

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->idService = $this->createMock(LocalIdService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->userMapper = $this->getMockBuilder(UserMapper::class)
			->setConstructorArgs([$this->db, $this->idService, $this->config])
			->onlyMethods(['getUser', 'insert'])
			->getMock();
	}


	public function dataCreate(): array {
		return [
			// unique uid
			[1, 'user@example.com', '2f891889123bd20b298fced19fc270faf0013523c5949ac629fbb8a0ac7d5d29', false, '2f891889123bd20b298fced19fc270faf0013523c5949ac629fbb8a0ac7d5d29'],
			[2, 'user@example.com', '4486f1cf00ba2d6d6c7c668a31b238c2b140d469f3bd86cc1a671e8136bac2c0', false, '4486f1cf00ba2d6d6c7c668a31b238c2b140d469f3bd86cc1a671e8136bac2c0'],
			[1, 'user1@example.com', 'f322b84fc7b972957d0f3cadb70d5528a77fc2b076ab4d7de4e5a41c44f729b9', false, 'f322b84fc7b972957d0f3cadb70d5528a77fc2b076ab4d7de4e5a41c44f729b9'],

			// no unique uid
			[1, 'user1@example.com', 'user1@example.com', false, 'user1@example.com'],
			[2, 'user1@example.com', 'user1@example.com', false, 'user1@example.com'],
			[2, 'very-long-user-email-adress-with-over-64-characters!!@example.com', 'very-long-user-email-adress-with-over-64-characters!!@example.com', false, 'd58d7aafa7642529dfa27dcf89f8d70dfdce97fbc8bcd80ef75f0bdb1b8fd527'],

			// id4me always uses unique uid
			[1, 'user1@example.com', '29d8436003cedbf722538e94a9f72e7412471403bbbc1799029424661317a571', true, '29d8436003cedbf722538e94a9f72e7412471403bbbc1799029424661317a571'],
			[1, 'user1@example.com', '29d8436003cedbf722538e94a9f72e7412471403bbbc1799029424661317a571', true, '29d8436003cedbf722538e94a9f72e7412471403bbbc1799029424661317a571'],

			// unique uid with provider prefix
			[1, 'user1@example.com', 'provider-user1@example.com', false, 'provider-user1@example.com'],
			[2, 'user1@example.com', 'provider-user1@example.com', false, 'provider-user1@example.com'],
		];
	}

	/** @dataProvider dataCreate */
	public function testCreate(int $providerId, string $sub, string $generatedId, bool $id4me, string $expected): void {
		$this->idService->expects(self::once())->method('getId')->with($providerId, $sub, $id4me)->willReturn($generatedId);

		$this->userMapper->expects(self::once())
			->method('getUser')
			->willThrowException(new DoesNotExistException('No user'));

		$this->userMapper->expects(self::once())
			->method('insert')
			->willReturnCallback(function ($arg) {
				return $arg;
			});

		Assert::assertEquals($expected, $this->userMapper->getOrCreate($providerId, $sub, $id4me)->getUserId());
	}
}
