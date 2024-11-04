<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
