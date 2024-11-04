<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version01021Date20220719114355 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->createTable('user_oidc_sessions');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 4,
		]);
		// https://openid.net/specs/openid-connect-core-1_0.html#IDToken
		$table->addColumn('sid', Types::STRING, [
			'notnull' => true,
			'length' => 256,
		]);
		$table->addColumn('sub', Types::STRING, [
			'notnull' => true,
			'length' => 256,
		]);
		$table->addColumn('iss', Types::STRING, [
			'notnull' => true,
			'length' => 512,
		]);
		$table->addColumn('authtoken_id', Types::INTEGER, [
			'notnull' => true,
			'length' => 4,
		]);
		$table->addColumn('nc_session_id', Types::STRING, [
			'notnull' => true,
			'length' => 200,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['created_at'], 'user_oidc_sess_crat');
		$table->addUniqueIndex(['sid'], 'user_oidc_sess_sid');
		$table->addUniqueIndex(['nc_session_id'], 'user_oidc_sess_sess_id');

		return $schema;
	}
}
