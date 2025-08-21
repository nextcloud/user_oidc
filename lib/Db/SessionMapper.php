<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCP\IDBConnection;
use OCP\Security\ICrypto;

/**
 * @extends QBMapper<Session>
 */
class SessionMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
		private ICrypto $crypto,
	) {
		parent::__construct($db, 'user_oidc_sessions', Session::class);
	}

	/**
	 * @param int $id
	 * @return Session
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getSession(int $id): Session {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find sessions by sub and iss
	 *
	 * @param string $sub
	 * @param string $iss
	 * @return Session[]
	 * @throws Exception
	 */
	public function findSessionsBySubAndIss(string $sub, string $iss): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('sub', $qb->createNamedParameter($sub, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('iss', $qb->createNamedParameter($iss, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntities($qb);
	}

	/**
	 * Find session by sid and optionally sub and iss
	 *
	 * @param string $sid
	 * @param string|null $sub
	 * @param string|null $iss
	 * @return Session
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function findSessionBySid(string $sid, ?string $sub = null, ?string $iss = null): Session {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('sid', $qb->createNamedParameter($sid, IQueryBuilder::PARAM_STR))
			);
		if ($sub !== null) {
			$qb->andWhere(
				$qb->expr()->eq('sub', $qb->createNamedParameter($sub, IQueryBuilder::PARAM_STR))
			);
		}
		if ($iss !== null) {
			$qb->andWhere(
				$qb->expr()->eq('iss', $qb->createNamedParameter($iss, IQueryBuilder::PARAM_STR))
			);
		}

		return $this->findEntity($qb);
	}

	/**
	 * @param int $authTokenId
	 * @param string $userId
	 * @return Session
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getSessionByAuthTokenAndUid(int $authTokenId, string $userId): Session {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('authtoken_id', $qb->createNamedParameter($authTokenId, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $ncSessionId
	 * @return int
	 * @throws Exception
	 */
	public function deleteFromNcSessionId(string $ncSessionId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('nc_session_id', $qb->createNamedParameter($ncSessionId, IQueryBuilder::PARAM_STR))
			);
		return $qb->executeStatement();
	}

	/**
	 * @param int $minCreationTimestamp
	 * @throws Exception
	 */
	public function cleanupSessions(int $minCreationTimestamp): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt('created_at', $qb->createNamedParameter($minCreationTimestamp, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}

	/**
	 * Create a session
	 *
	 * @param string $sid
	 * @param string $sub
	 * @param string $iss
	 * @param int $authtokenId
	 * @param string $ncSessionid
	 * @param string $idToken
	 * @param string $userId
	 * @param int $providerId
	 * @param bool $idpSessionClosed
	 * @return Session|null
	 * @throws Exception
	 */
	public function createSession(
		string $sid, string $sub, string $iss, int $authtokenId, string $ncSessionid,
		string $idToken, string $userId, int $providerId, bool $idpSessionClosed = false,
	): ?Session {
		try {
			// do not create if one with same sid already exists (which should not happen)
			return $this->findSessionBySid($sid);
		} catch (MultipleObjectsReturnedException $e) {
			// this can't happen
			return null;
		} catch (DoesNotExistException $e) {
		}

		$createdAt = (new DateTime())->getTimestamp();

		$session = new Session();
		$session->setSid($sid);
		$session->setSub($sub);
		$session->setIss($iss);
		$session->setAuthtokenId($authtokenId);
		$session->setNcSessionId($ncSessionid);
		$session->setCreatedAt($createdAt);
		$session->setIdToken($this->crypto->encrypt($idToken));
		$session->setUserId($userId);
		$session->setProviderId($providerId);
		$session->setIdpSessionClosed($idpSessionClosed ? 1 : 0);
		return $this->insert($session);
	}
}
