<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCA\UserOIDC\Service\LocalIdService;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Db\QBMapper;
use OCP\Cache\CappedMemoryCache;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * @extends QBMapper<User>
 */
class UserMapper extends QBMapper {

	private CappedMemoryCache $userCache;

	public function __construct(
		IDBConnection $db,
		private LocalIdService $idService,
		private IConfig $config,
	) {
		parent::__construct($db, 'user_oidc', User::class);
		$this->userCache = new CappedMemoryCache();
	}

	/**
	 * @param string $uid
	 * @return User
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function getUser(string $uid): User {
		$cachedUser = $this->userCache->get($uid);
		if ($cachedUser !== null) {
			return $cachedUser;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR))
			);

		/** @var User $user */
		$user = $this->findEntity($qb);
		$this->userCache->set($uid, $user);
		return $user;
	}

	public function find(string $search, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$matchEmails = !isset($oidcSystemConfig['user_search_match_emails']) || $oidcSystemConfig['user_search_match_emails'] === true;
		if ($matchEmails) {
			$qb->select('user_id', 'display_name')
				->from($this->getTableName(), 'u')
				->leftJoin('u', 'preferences', 'p', $qb->expr()->andX(
					$qb->expr()->eq('userid', 'user_id'),
					$qb->expr()->eq('appid', $qb->expr()->literal('settings')),
					$qb->expr()->eq('configkey', $qb->expr()->literal('email')))
				)
				->where($qb->expr()->iLike('user_id', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orWhere($qb->expr()->iLike('display_name', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orWhere($qb->expr()->iLike('configvalue', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orderBy($qb->func()->lower('user_id'), 'ASC');
			if ($limit !== null) {
				$qb->setMaxResults($limit);
			}
			if ($offset !== null) {
				$qb->setFirstResult($offset);
			}
		} else {
			$qb->select('user_id', 'display_name')
				->from($this->getTableName())
				->where($qb->expr()->iLike('user_id', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orWhere($qb->expr()->iLike('display_name', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orderBy($qb->func()->lower('user_id'), 'ASC');
			if ($limit !== null) {
				$qb->setMaxResults($limit);
			}
			if ($offset !== null) {
				$qb->setFirstResult($offset);
			}
		}

		return $this->findEntities($qb);
	}

	public function findDisplayNames(string $search, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$matchEmails = !isset($oidcSystemConfig['user_search_match_emails']) || $oidcSystemConfig['user_search_match_emails'] === true;
		if ($matchEmails) {
			$qb->select('user_id', 'display_name')
				->from($this->getTableName(), 'u')
				->leftJoin('u', 'preferences', 'p', $qb->expr()->andX(
					$qb->expr()->eq('userid', 'user_id'),
					$qb->expr()->eq('appid', $qb->expr()->literal('settings')),
					$qb->expr()->eq('configkey', $qb->expr()->literal('email')))
				)
				->where($qb->expr()->iLike('user_id', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orWhere($qb->expr()->iLike('display_name', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orWhere($qb->expr()->iLike('configvalue', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orderBy($qb->func()->lower('user_id'), 'ASC');
			if ($limit !== null) {
				$qb->setMaxResults($limit);
			}
			if ($offset !== null) {
				$qb->setFirstResult($offset);
			}
		} else {
			$qb->select('user_id', 'display_name')
				->from($this->getTableName())
				->where($qb->expr()->iLike('user_id', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orWhere($qb->expr()->iLike('display_name', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
				->orderBy($qb->func()->lower('user_id'), 'ASC');
			if ($limit !== null) {
				$qb->setMaxResults($limit);
			}
			if ($offset !== null) {
				$qb->setFirstResult($offset);
			}
		}

		$result = $qb->executeQuery();
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

	/**
	 * @param non-empty-string $sub Sub of the user hashed if it exceed 256 characters
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	protected function getByProviderAndSub(int $providerId, string $sub): User {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('provider_id', $qb->createNamedParameter($providerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('sub', $qb->createNamedParameter($sub, IQueryBuilder::PARAM_STR)));

		/** @var User $user */
		$user = $this->findEntity($qb);
		$this->userCache->set($user->getUserId(), $user);
		return $user;
	}

	/**
	 * @param non-empty-string $sub
	 */
	public function getOrCreate(int $providerId, string $sub, bool $id4me = false): User {
		// the sub is the stable identifier we want to keep around, so guard it
		// against the column length the same way the userId is further below
		$storedSub = strlen($sub) > 256 ? hash('sha256', $sub) : $sub;

		try {
			// look this identity up by its immutable (provider, sub) pair
			// first: if it has been provisioned before, this always returns
			// its existing account, even if the uid-generation settings
			// changed since - this is what keeps a provider's own attribute
			// drift from forking the account into a second, empty one
			return $this->getByProviderAndSub($providerId, $storedSub);
		} catch (IMapperException $e) {
			// not seen under this provider+sub yet, fall through below
		}

		$userId = $this->idService->getId($providerId, $sub, $id4me);

		if (strlen($userId) > 64) {
			$userId = hash('sha256', $userId);
		}

		try {
			$user = $this->getUser($userId);
			if ($user->getProviderId() === null || $user->getSub() === null) {
				// backfill accounts that were provisioned before these columns
				// existed, so their next login takes the fast path above
				$user->setProviderId($providerId);
				$user->setSub($storedSub);
				$user = $this->update($user);
				$this->userCache->set($userId, $user);
			}
			return $user;
		} catch (IMapperException $e) {
			// just ignore and continue
		}

		$user = new User();
		$user->setUserId($userId);
		$user->setDisplayName('');
		$user->setProviderId($providerId);
		$user->setSub($storedSub);
		$user = $this->insert($user);
		$this->userCache->set($userId, $user);
		return $user;
	}

	/**
	 * Count the total number of users provisioned by the OIDC backend.
	 *
	 * @return int the number of rows in the user_oidc table
	 */
	public function countUsers(): int {
		$qb = $this->db->getQueryBuilder();

		$qb->selectAlias($qb->func()->count('*'), 'user_count')
			->from($this->getTableName());

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * @return array<string, string>
	 */
	public function searchKnownUsersByDisplayName(string $searcher, string $pattern, ?int $limit = null, ?int $offset = null): array {
		$query = $this->db->getQueryBuilder();

		$query->select('u.user_id', 'u.display_name')
			->from($this->getTableName(), 'u')
			->leftJoin('u', 'known_users', 'k', $query->expr()->andX(
				$query->expr()->eq('k.known_user', 'u.user_id'),
				$query->expr()->eq('k.known_to', $query->createNamedParameter($searcher))
			))
			->where($query->expr()->eq('k.known_to', $query->createNamedParameter($searcher)))
			->andWhere($query->expr()->orX(
				$query->expr()->iLike('u.user_id', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($pattern) . '%')),
				$query->expr()->iLike('u.display_name', $query->createNamedParameter('%' . $this->db->escapeLikeParameter($pattern) . '%'))
			))
			->orderBy('u.display_name', 'ASC')
			->addOrderBy('u.user_id', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$result = $query->executeQuery();
		$displayNames = [];
		while ($row = $result->fetch()) {
			$displayNames[(string)$row['user_id']] = (string)$row['display_name'];
		}
		$result->closeCursor();

		return $displayNames;
	}
}
