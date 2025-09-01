<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method \string getIdentifier()
 * @method \void setIdentifier(string $identifier)
 * @method \string getClientId()
 * @method \void setClientId(string $clientId)
 * @method \string getClientSecret()
 * @method \void setClientSecret(string $clientSecret)
 * @method \string|\null getDiscoveryEndpoint()
 * @method \void setDiscoveryEndpoint(?string $discoveryEndpoint)
 * @method \string|\null getEndSessionEndpoint()
 * @method \void setEndSessionEndpoint(?string $endSessionEndpoint)
 * @method \string|\null getPostLogoutUri()
 * @method \void setPostLogoutUri(?string $postLogoutUri)
 * @method \void setScope(string $scope)
 */
class Provider extends Entity implements \JsonSerializable {

	/** @var string */
	protected $identifier;
	/** @var string */
	protected $clientId;
	/** @var string */
	protected $clientSecret;
	/** @var ?string */
	protected $discoveryEndpoint;
	/** @var ?string */
	protected $endSessionEndpoint;
	/** @var string */
	protected $postLogoutUri;
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
			'id' => $this->getId(),
			'identifier' => $this->getIdentifier(),
			'clientId' => $this->getClientId(),
			'discoveryEndpoint' => $this->getDiscoveryEndpoint(),
			'endSessionEndpoint' => $this->getEndSessionEndpoint(),
			'postLogoutUri' => $this->getPostLogoutUri(),
			'scope' => trim($this->getScope()),
		];
	}
}
