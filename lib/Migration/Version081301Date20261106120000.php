<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * 8.11 to 8.13 stored the uid claim as the sub. Clear these keys, they are recorded again with the
 * real subject on the next login. Keys of providers whose uid claim is "sub" are kept, unless a
 * user_id_regexp changed the value.
 */
class Version081301Date20261106120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private ProviderService $providerService,
		private ProviderMapper $providerMapper,
		private LoggerInterface $logger,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$userIdRegexp = is_array($oidcSystemConfig)
			&& isset($oidcSystemConfig['user_id_regexp'])
			&& is_string($oidcSystemConfig['user_id_regexp'])
			&& $oidcSystemConfig['user_id_regexp'] !== '';

		$existingProviderIds = array_map(static fn (Provider $provider): int => (int)$provider->getId(), $this->providerMapper->getProviders());

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('provider_id')
			->from('user_oidc')
			->where($qb->expr()->isNotNull('provider_id'));
		$result = $qb->executeQuery();
		$providerIds = [];
		while (($providerId = $result->fetchOne()) !== false) {
			$providerId = (int)$providerId;
			$uidClaim = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, ProviderService::SETTING_MAPPING_UID_DEFAULT);
			if ($userIdRegexp || $uidClaim !== 'sub' || !in_array($providerId, $existingProviderIds, true)) {
				$providerIds[] = $providerId;
			}
		}
		$result->closeCursor();

		if ($providerIds === []) {
			return;
		}

		$update = $this->db->getQueryBuilder();
		$update->update('user_oidc')
			->set('provider_id', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('sub', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($update->expr()->in('provider_id', $update->createNamedParameter($providerIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$count = $update->executeStatement();

		$output->info('Cleared the provider key of ' . $count . ' account(s), it is recorded again on their next login');
		$this->logger->info('Cleared the provider key of accounts whose sub held the uid claim', ['app' => 'user_oidc', 'providerIds' => $providerIds, 'count' => $count]);
	}
}
