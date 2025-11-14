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
