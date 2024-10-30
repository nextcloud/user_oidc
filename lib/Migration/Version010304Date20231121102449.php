<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version010304Date20231121102449 extends SimpleMigrationStep {

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

		$schemaChanged = false;
		foreach (['user_oidc_providers', 'user_oidc_id4me'] as $tableName) {
			if ($schema->hasTable($tableName)) {
				$table = $schema->getTable($tableName);
				if ($table->hasColumn('client_secret')) {
					$column = $table->getColumn('client_secret');
					$column->setLength(2048);
					$schemaChanged = true;
				}
				if ($table->hasColumn('client_id')) {
					$column = $table->getColumn('client_id');
					$column->setLength(2048);
					$schemaChanged = true;
				}
			}
		}
		if ($schemaChanged) {
			return $schema;
		}
		return null;
	}
}
