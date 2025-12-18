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
 */
class Id4Me extends Entity {

	/** @var string */
	protected $identifier;

	/** @var string */
	protected $clientId;

	/** @var string */
	protected $clientSecret;
}
