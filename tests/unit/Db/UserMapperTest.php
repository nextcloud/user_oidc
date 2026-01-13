<?php

/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);


use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserMapperTest extends TestCase {

	/**
	 * @var ProviderService|MockObject
	 */
	private $providerService;
	/** @var UserMapper|MockObject */
	private $userMapper;

	public function setUp(): void {
		parent::setUp();

		$this->providerService = $this->createMock(ProviderService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->userMapper = $this->getMockBuilder(UserMapper::class)
			->setConstructorArgs([$this->db, $this->providerService])
			->setMethods(['getUser', 'insert'])
			->getMock();
	}


	public function dataCreate(): array {
		return [
			// unique uid
			[1, 'user@example.com', false, true, '2f891889123bd20b298fced19fc270faf0013523c5949ac629fbb8a0ac7d5d29'],
			[2, 'user@example.com', false, true, '4486f1cf00ba2d6d6c7c668a31b238c2b140d469f3bd86cc1a671e8136bac2c0'],
			[1, 'user1@example.com', false, true, 'f322b84fc7b972957d0f3cadb70d5528a77fc2b076ab4d7de4e5a41c44f729b9'],

			// no unique uid
			[1, 'user1@example.com', false, false, 'user1@example.com'],
			[2, 'user1@example.com', false, false, 'user1@example.com'],

			// id4me always uses unique uid
			[1, 'user1@example.com', true, false, '29d8436003cedbf722538e94a9f72e7412471403bbbc1799029424661317a571'],
			[1, 'user1@example.com', true, true, '29d8436003cedbf722538e94a9f72e7412471403bbbc1799029424661317a571'],
		];
	}

	/** @dataProvider dataCreate */
	public function testCreate(int $providerId, string $sub, bool $id4me, bool $uniqueId, string $expected): void {
		$this->providerService->expects(self::once())
			->method('getSetting')
			->with($providerId, ProviderService::SETTING_UNIQUE_UID, '1')
			->willReturn($uniqueId ? '1' : '0');
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
