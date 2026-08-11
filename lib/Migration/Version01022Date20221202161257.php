<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use Doctrine\DBAL\Schema\SchemaException;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version01022Date20221202161257 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 * @throws SchemaException
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		$somethingChanged = false;

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('user_oidc_sessions')) {
			$table = $schema->getTable('user_oidc_sessions');
			$indexes = $table->getIndexes();
			foreach ($indexes as $index) {
				$columns = $index->getColumns();
				// fix created_at index which is not unique
				if ($index->isUnique() && $this->startsWithColumn($columns, 'created_at')) {
					$table->dropIndex($index->getName());
					$table->addIndex(['created_at'], 'user_oidc_sess_crat');
					$somethingChanged = true;
				}
				// rename indexes on sid and nc_session_id if needed
				if ($index->isUnique() && $this->startsWithColumn($columns, 'sid') && $index->getName() !== 'user_oidc_sess_sid') {
					$table->renameIndex($index->getName(), 'user_oidc_sess_sid');
					$somethingChanged = true;
				}
				if ($index->isUnique() && $this->startsWithColumn($columns, 'nc_session_id') && $index->getName() !== 'user_oidc_sess_sess_id') {
					$table->renameIndex($index->getName(), 'user_oidc_sess_sess_id');
					$somethingChanged = true;
				}
			}
		}

		if ($schema->hasTable('user_oidc_providers')) {
			$table = $schema->getTable('user_oidc_providers');
			$indexes = $table->getIndexes();
			foreach ($indexes as $index) {
				$columns = $index->getColumns();
				// rename index on identifier if needed
				if ($index->isUnique() && $this->startsWithColumn($columns, 'identifier') && $index->getName() !== 'user_oidc_prov_idtf') {
					$table->renameIndex($index->getName(), 'user_oidc_prov_idtf');
					$somethingChanged = true;
				}
			}
		}

		return $somethingChanged ? $schema : null;
	}

	/**
	 * Whether the first column of an index is the given column.
	 *
	 * Replaces IIndex::hasColumnAtPosition() which was removed from the public
	 * API in Nextcloud 35. Column names are compared like Doctrine did, without
	 * quoting and case insensitively, so $columnName must be given lowercase.
	 *
	 * @param list<string> $indexColumns
	 */
	private function startsWithColumn(array $indexColumns, string $columnName): bool {
		if ($indexColumns === []) {
			return false;
		}

		return strtolower(str_replace(['`', '"', '[', ']'], '', $indexColumns[0])) === $columnName;
	}
}
