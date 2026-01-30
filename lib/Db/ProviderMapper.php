<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
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
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function getProvider(int $id): Provider {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find provider by provider identifier, the admin-given name for
	 * the provider configuration.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findProviderByIdentifier(string $identifier): Provider {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('identifier', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
			);

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
		?string $clientid = null,
		?string $clientsecret = null,
		?string $discoveryuri = null,
		string $scope = 'openid email profile',
		?string $endsessionendpointuri = null,
		?string $postLogoutUri = null,
	): Provider|Entity {
		try {
			$provider = $this->findProviderByIdentifier($identifier);
		} catch (DoesNotExistException $eNotExist) {
			$provider = null;
		}

		if ($provider === null) {
			$provider = new Provider();
			if ($clientid === null || $clientsecret === null || $discoveryuri === null) {
				throw new DoesNotExistException('Provider must be created. All provider parameters required.');
			}
			$provider->setIdentifier($identifier);
			$provider->setClientId($clientid);
			$provider->setClientSecret($clientsecret);
			$provider->setDiscoveryEndpoint($discoveryuri);
			$provider->setEndSessionEndpoint($endsessionendpointuri);
			$provider->setPostLogoutUri($postLogoutUri);
			$provider->setScope($scope);

			return $this->insert($provider);
		} else {
			if ($clientid !== null) {
				$provider->setClientId($clientid);
			}
			if ($clientsecret !== null) {
				$provider->setClientSecret($clientsecret);
			}
			if ($discoveryuri !== null) {
				$provider->setDiscoveryEndpoint($discoveryuri);
			}
			if ($endsessionendpointuri !== null) {
				$provider->setEndSessionEndpoint($endsessionendpointuri ?: null);
			}
			if ($postLogoutUri !== null && $postLogoutUri !== '') {
				$provider->setPostLogoutUri($postLogoutUri);
			} else {
				$provider->setPostLogoutUri(null);
			}
			$provider->setScope($scope);

			return $this->update($provider);
		}
	}
}
