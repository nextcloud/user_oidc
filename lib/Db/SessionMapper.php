<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022, Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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

use DateTime;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

use OCP\AppFramework\Db\DoesNotExistException;

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
	 * @return Session[]
	 */
	public function getSessions() {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
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
