<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method \string getSid()
 * @method \void setSid(string $sid)
 * @method \string getSub()
 * @method \void setSub(string $sub)
 * @method \string getIss()
 * @method \void setIss(string $iss)
 * @method \int getAuthtokenId()
 * @method \void setAuthtokenId(int $authtokenId)
 * @method \string getNcSessionId()
 * @method \void setNcSessionId(string $ncSessionId)
 * @method \int getCreatedAt()
 * @method \void setCreatedAt(int $createdAt)
 * @method \string|\null getIdToken()
 * @method \void setIdToken(?string $idToken)
 * @method \string|\null getUserId()
 * @method \void setUserId(?string $userId)
 * @method \int getProviderId()
 * @method \void setProviderId(int $providerId)
 * @method \int getIdpSessionClosed()
 * @method \void setIdpSessionClosed(int $idpSessionClosed)
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
	/** @var ?string */
	protected $idToken;
	/** @var ?string */
	protected $userId;
	/** @var int */
	protected $providerId;
	/** @var int */
	protected $idpSessionClosed;

	public function __construct() {
		$this->addType('sid', 'string');
		$this->addType('sub', 'string');
		$this->addType('iss', 'string');
		$this->addType('authtoken_id', 'integer');
		$this->addType('nc_session_id', 'string');
		$this->addType('created_at', 'integer');
		$this->addType('id_token', 'string');
		$this->addType('user_id', 'string');
		$this->addType('provider_id', 'integer');
		$this->addType('idp_session_closed', 'integer');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'sid' => $this->getSid(),
			'sub' => $this->getSub(),
			'iss' => $this->getIss(),
			'authtoken_id' => $this->getAuthtokenId(),
			'nc_session_id' => $this->getNcSessionId(),
			'created_at' => $this->getCreatedAt(),
			'user_id' => $this->getUserId(),
			'provider_id' => $this->getProviderId(),
			'idp_session_closed' => $this->getIdpSessionClosed() !== 0,
		];
	}
}
