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
			'scope' => trim($this->scope),
		];
	}
}
