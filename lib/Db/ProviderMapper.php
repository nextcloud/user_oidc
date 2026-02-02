<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Provider>
 */
class ProviderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'user_oidc_providers', Provider::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function getProvider(int $id): Provider {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Find provider by provider identifier, the admin-given name for
	 * the provider configuration.
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findProviderByIdentifier(string $identifier): Provider {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Provider[]
	 */
	public function getProviders(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
	}

	/**
	 * Create or update provider settings
	 *
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function createOrUpdateProvider(
		string $identifier,
		?string $clientId = null,
		?string $clientSecret = null,
		?string $discoveryUri = null,
		string $scope = 'openid email profile',
		?string $endSessionEndpointUri = null,
		?string $postLogoutUri = null,
	): Provider {
		try {
			$provider = $this->findProviderByIdentifier($identifier);

			// Update existing provider
			if ($clientId !== null) {
				$provider->setClientId($clientId);
			}
			if ($clientSecret !== null) {
				$provider->setClientSecret($clientSecret);
			}
			if ($discoveryUri !== null) {
				$provider->setDiscoveryEndpoint($discoveryUri);
			}
			if ($endSessionEndpointUri !== null) {
				$provider->setEndSessionEndpoint($endSessionEndpointUri);
			}
			if ($postLogoutUri !== null) {
				$provider->setPostLogoutUri($postLogoutUri);
			}
			$provider->setScope($scope);

			return $this->update($provider);
		} catch (DoesNotExistException $e) {
			// Create new provider
			if ($clientId === null || $clientSecret === null || $discoveryUri === null) {
				throw new DoesNotExistException('Provider must be created. All provider parameters required.');
			}

			$provider = new Provider();
			$provider->setIdentifier($identifier);
			$provider->setClientId($clientId);
			$provider->setClientSecret($clientSecret);
			$provider->setDiscoveryEndpoint($discoveryUri);
			$provider->setEndSessionEndpoint($endSessionEndpointUri);
			$provider->setPostLogoutUri($postLogoutUri);
			$provider->setScope($scope);

			return $this->insert($provider);
		}
	}
}
