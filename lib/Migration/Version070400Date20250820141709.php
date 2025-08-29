<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version070400Date20250820141709 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$schemaChanged = false;

		if ($schema->hasTable('user_oidc_sessions')) {
			$table = $schema->getTable('user_oidc_sessions');
			if (!$table->hasColumn('id_token')) {
				$table->addColumn('id_token', Types::TEXT, [
					'notnull' => false,
				]);
				$schemaChanged = true;
			}
			if (!$table->hasColumn('user_id')) {
				$table->addColumn('user_id', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => null,
				]);
				$schemaChanged = true;
			}
			if (!$table->hasColumn('provider_id')) {
				$table->addColumn('provider_id', Types::BIGINT, [
					'notnull' => true,
					'default' => 0,
					'unsigned' => true,
				]);
				$schemaChanged = true;
			}
			if (!$table->hasColumn('idp_session_closed')) {
				$table->addColumn('idp_session_closed', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
					'unsigned' => true,
				]);
				$schemaChanged = true;
			}
		}

		return $schemaChanged ? $schema : null;
	}
}
