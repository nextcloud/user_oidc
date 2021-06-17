<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;


class ProviderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'user_oidc_providers', Provider::class);
	}

	/**
	 * @param int $id
	 * @return Provider
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function getProvider(int $id): Provider {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find provider by provider identifier, the admin-given name for
	 * the provider configuration.
	 * @param string $identifier
	 * @return Provider
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function findProviderByIdentifier(string $identifier): Provider {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('identifier', $qb->createNamedParameter($identifier))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @return Provider[]
	 */
	public function getProviders() {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
	}

	/**
	 * Create or update provider settinngs
	 * 
	 * @param string identifier
	 * @param string|null $clientid
	 * @param string|null $clientsecret
	 * @param string|null $discoveryuri
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function createOrUpdateProvider(string $identifier, string $clientid = null,
	   								string $clientsecret = null, string $discoveryuri = null) {
		try {
			$provider = $this->findProviderByIdentifier($identifier);
		} catch (DoesNotExistException $eNotExist) {
			$provider = null;
		}

		if ($provider === null) {
			$provider = new Provider();
			if (( $clientid === null ) || ( $clientsecret === null ) || ( $discoveryuri === null )) {
				throw new DoesNotExistException("Provider must be created. All provider parameters required.");
			}
			$provider->setIdentifier($identifier);
			$provider->setClientId($clientid);
			$provider->setClientSecret($clientsecret);
			$provider->setDiscoveryEndpoint($discoveryuri);
		} else {
			if ( $clientid !== null ) {
				$provider->setClientId($clientid);
			}
			if ( $clientsecret !== null ) {
				$provider->setClientSecret($clientsecret);
			}
			if ( $disvoveryuri !== null ) {
				$provider->setDiscoveryEndpoint($discoveryuri);
			}
		}

		return $this->insertOrUpdate($provider);
	}

	/**
	 * Create or update provider settinngs
	 * 
	 * @param string identifier
	 */
	public function deleteProvider(string $identifier) {
		$provider = $this->findProviderByIdentifier($identifier);
		if (null !== $provider) {
			return $this->delete($provider);
		} else {
			return null;
		}
	}

}
