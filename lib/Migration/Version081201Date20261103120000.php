<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Clear the (provider_id, sub) key of ID4me accounts. It holds an ID4me
 * authority id, which can equal an unrelated OIDC provider id.
 */
class Version081201Date20261103120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'provider_id', 'sub')
			->from('user_oidc')
			->where($qb->expr()->isNotNull('provider_id'))
			->andWhere($qb->expr()->isNotNull('sub'));
		$result = $qb->executeQuery();

		// ID4me uids are always sha256("<authority id>_1_<sub>"), see LocalIdService::getId()
		$id4meUserIds = [];
		while ($row = $result->fetch()) {
			if ($row['user_id'] === hash('sha256', $row['provider_id'] . '_1_' . $row['sub'])) {
				$id4meUserIds[] = $row['user_id'];
			}
		}
		$result->closeCursor();

		$update = $this->db->getQueryBuilder();
		$update->update('user_oidc')
			->set('provider_id', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('sub', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($update->expr()->in('user_id', $update->createParameter('userIds')));
		foreach (array_chunk($id4meUserIds, 1000) as $chunk) {
			$update->setParameter('userIds', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
			$update->executeStatement();
		}

		if ($id4meUserIds !== []) {
			$output->info('Cleared the provider key of ' . count($id4meUserIds) . ' ID4me account(s)');
			$this->logger->warning('Cleared the provider key of ID4me accounts', ['app' => 'user_oidc', 'userIds' => $id4meUserIds]);
		}
	}
}
