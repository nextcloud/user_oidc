<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getIdentifier()
 * @method void setIdentifier(string $identifier)
 * @method string getClientId()
 * @method void setClientId(string $clientId)
 * @method string getClientSecret()
 * @method void setClientSecret(string $clientSecret)
 * @method string getDiscoveryEndpoint()
 * @method void setDiscoveryEndpoint(string $discoveryEndpoint)
 * @method string getEndSessionEndpoint()
 * @method void setEndSessionEndpoint(string $endSessionEndpoint)
 * @method void setScope(string $scope)
 */
class Provider extends Entity implements \JsonSerializable {

	/** @var string */
	protected $identifier;

	/** @var string */
	protected $clientId;

	/** @var string */
	protected $clientSecret;

	/** @var string */
	protected $discoveryEndpoint;

	/** @var string */
	protected $endSessionEndpoint;

	/** @var string */
	protected $scope;

	/**
	 * @return string
	 */
	public function getScope(): string {
		return $this->scope ?: ' ';
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'identifier' => $this->identifier,
			'clientId' => $this->clientId,
			'discoveryEndpoint' => $this->discoveryEndpoint,
			'endSessionEndpoint' => $this->endSessionEndpoint,
			'scope' => trim($this->getScope()),
		];
	}
}
