<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version010303Date20230602125945 extends SimpleMigrationStep {

	/**
	 * @var IDBConnection
	 */
	private $connection;
	/**
	 * @var ICrypto
	 */
	private $crypto;

	public function __construct(
		IDBConnection $connection,
		ICrypto $crypto,
	) {
		$this->connection = $connection;
		$this->crypto = $crypto;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (['user_oidc_providers', 'user_oidc_id4me'] as $tableName) {
			if ($schema->hasTable($tableName)) {
				$table = $schema->getTable($tableName);
				if ($table->hasColumn('client_secret')) {
					$column = $table->getColumn('client_secret');
					$column->setLength(512);
					return $schema;
				}
			}
		}

		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// update secrets in user_oidc_providers and user_oidc_id4me
		foreach (['user_oidc_providers', 'user_oidc_id4me'] as $tableName) {
			$qbUpdate = $this->connection->getQueryBuilder();
			$qbUpdate->update($tableName)
				->set('client_secret', $qbUpdate->createParameter('updateSecret'))
				->where(
					$qbUpdate->expr()->eq('id', $qbUpdate->createParameter('updateId'))
				);

			$qbSelect = $this->connection->getQueryBuilder();
			$qbSelect->select('id', 'client_secret')
				->from($tableName);
			$req = $qbSelect->executeQuery();
			while ($row = $req->fetch()) {
				$id = $row['id'];
				$secret = $row['client_secret'];
				$encryptedSecret = $this->crypto->encrypt($secret);
				$qbUpdate->setParameter('updateSecret', $encryptedSecret, IQueryBuilder::PARAM_STR);
				$qbUpdate->setParameter('updateId', $id, IQueryBuilder::PARAM_INT);
				$qbUpdate->executeStatement();
			}
			$req->closeCursor();
		}
	}
}
