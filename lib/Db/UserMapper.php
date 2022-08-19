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

use OCA\UserOIDC\Service\IdService;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OC\Cache\CappedMemoryCache;

class UserMapper extends QBMapper {
	/** @var IdService */
	private $idService;

	/** @var CappedMemoryCache<User> */
	private $userCache;

	public function __construct(IDBConnection $db, IdService $idService) {
		parent::__construct($db, 'user_oidc', User::class);
		$this->idService = $idService;
		$this->userCache = new CappedMemoryCache();
	}

	/**
	 * @param string $uid
	 * @return User
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function getUser(string $uid): User {
		if ($this->userCache->hasKey($uid)) {
			return $this->userCache->get($uid);
		}
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($uid))
			);

		/** @var User $user */
		$user = $this->findEntity($qb);
		$this->userCache->set($uid, $user);
		return $user;
	}

	public function find(string $search, $limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->iLike('user_id', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orWhere($qb->expr()->iLike('display_name', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orderBy($qb->func()->lower('user_id'), 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	public function findDisplayNames(string $search, $limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->iLike('user_id', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orWhere($qb->expr()->iLike('display_name', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orderBy($qb->func()->lower('user_id'), 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$result = $qb->execute();
		$displayNames = [];
		while ($row = $result->fetch()) {
			$displayNames[(string)$row['user_id']] = (string)$row['display_name'];
		}

		return $displayNames;
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
		$userId = $this->idService->getId($providerId, $sub, $id4me);

		if (strlen($userId) > 64) {
			$userId = hash('sha256', $userId);
		}

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
