<?php

declare(strict_types=1);

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version00006Date20210630120251 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('user_oidc_providers');
		$table->addUniqueIndex(['identifier'], 'user_oidc_prov_idtf');

		return $schema;
	}
}
