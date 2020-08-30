<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class UserMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'user_oidc', User::class);
	}

	/**
	 * @param string $uid
	 * @return User
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function getUser(string $uid): User {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($uid))
			);

		return $this->findEntity($qb);
	}

	public function userExists(string $uid): bool {
		try {
			$this->getUser($uid);
			return true;
		} catch (IMapperException $e) {
			return false;
		}
	}

	public function getOrCreate(int $providerId, string $sub, bool $id4me = false): User {
		$userId = $providerId . '_';

		if ($id4me) {
			$userId .= '1_';
		} else {
			$userId .= '0_';
		}

		$userId .= $sub;

		$userId = hash('sha256', $userId);

		try {
			return $this->getUser($userId);
		} catch (IMapperException $e) {
			// just ignore and continue
		}

		$user = new User();
		$user->setUserId($userId);
		return $this->insert($user);
	}
}
