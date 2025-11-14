<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Id4Me>
 */
class Id4MeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'user_oidc_id4me', Id4Me::class);
	}

	/**
	 * @param string $identifier
	 * @return Id4Me
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findByIdentifier(string $identifier): Id4Me {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('identifier', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}
}
