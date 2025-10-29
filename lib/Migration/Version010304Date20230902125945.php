<?php

declare(strict_types=1);

/**
 * @copyright Copyright 2023, Julien Veyssier <julien-nc@posteo.net>
 *
 * @author B. Rederlechner <bernd.rederlechner@t-systems.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version010304Date20230902125945 extends SimpleMigrationStep {

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
		$tableName = 'user_oidc_providers';

		if ($schema->hasTable($tableName)) {
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('bearer_secret')) {
				$column = $table->getColumn('bearer_secret');
				$column->setLength(512);
				return $schema;
			}
		}

		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		$tableName = 'user_oidc_providers';

		// update secrets in user_oidc_providers and user_oidc_id4me
		$qbUpdate = $this->connection->getQueryBuilder();
		$qbUpdate->update($tableName)
			->set('bearer_secret', $qbUpdate->createParameter('updateSecret'))
			->where(
				$qbUpdate->expr()->eq('id', $qbUpdate->createParameter('updateId'))
			);

		$qbSelect = $this->connection->getQueryBuilder();
		$qbSelect->select('id', 'bearer_secret')
			->from($tableName);
		$req = $qbSelect->executeQuery();
		while ($row = $req->fetch()) {
			$id = $row['id'];
			$secret = $row['bearer_secret'];
			$encryptedSecret = $this->crypto->encrypt($secret);
			$qbUpdate->setParameter('updateSecret', $encryptedSecret, IQueryBuilder::PARAM_STR);
			$qbUpdate->setParameter('updateId', $id, IQueryBuilder::PARAM_INT);
			$qbUpdate->executeStatement();
		}
		$req->closeCursor();
	}
}
