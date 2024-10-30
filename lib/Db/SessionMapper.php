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
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCP\IDBConnection;

/**
 * @extends QBMapper<Session>
 */
class SessionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'user_oidc_sessions', Session::class);
	}

	/**
	 * @param int $id
	 * @return Session
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
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
	 * Find session by sid (from the OIDC id token)
	 *
	 * @param string $sid
	 * @return Session
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findSessionBySid(string $sid): Session {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('sid', $qb->createNamedParameter($sid, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $ncSessionId
	 * @return int
	 * @throws \OCP\DB\Exception
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
	 * @throws \OCP\DB\Exception
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
	 * @return mixed|Session|\OCP\AppFramework\Db\Entity
	 */
	public function createSession(string $sid, string $sub, string $iss, int $authtokenId, string $ncSessionid) {
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
		return $this->insert($session);
	}
}
