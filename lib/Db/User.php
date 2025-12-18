<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method \string getUserId()
 * @method \void setUserId(string $userId)
 * @method \string getDisplayName()
 * @method \void setDisplayName(string $displayName)
 */
class User extends Entity {

	/** @var string */
	protected $userId;

	/** @var string */
	protected $displayName;

	public function __construct() {
		$this->addType('userId', Types::STRING);
	}
}
