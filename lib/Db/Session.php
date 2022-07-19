<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022, Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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
 * @method string getSid()
 * @method void setSid(string $sid)
 * @method string getSub()
 * @method void setSub(string $sub)
 * @method string getIss()
 * @method void setIss(string $iss)
 * @method int getAuthtokenId()
 * @method void setAuthtokenId(int $authtokenId)
 * @method string getNcSessionId()
 * @method void setNcSessionId(string $ncSessionId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Session extends Entity implements \JsonSerializable {

	/** @var string */
	protected $sid;

	/** @var string */
	protected $sub;

	/** @var string */
	protected $iss;

	/** @var int */
	protected $authtokenId;

	/** @var string */
	protected $ncSessionId;

	/** @var int */
	protected $createdAt;

	public function __construct() {
		$this->addType('sid', 'string');
		$this->addType('sub', 'string');
		$this->addType('iss', 'string');
		$this->addType('authtoken_id', 'integer');
		$this->addType('nc_session_id', 'string');
		$this->addType('created_at', 'integer');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'sid' => $this->sid,
			'sub' => $this->sub,
			'iss' => $this->iss,
			'authtoken_id' => $this->authtokenId,
			'nc_session_id' => $this->ncSessionId,
			'created_at' => $this->createdAt,
		];
	}
}
