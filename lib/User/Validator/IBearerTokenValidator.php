<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\User\Validator;

use OCA\UserOIDC\Db\Provider;

interface IBearerTokenValidator {

	/**
	 * Validate the passed token and return the matched user id if found
	 *
	 * @param Provider $provider
	 * @param string $bearerToken
	 * @return string|null user id or null if the token was not valid
	 */
	public function isValidBearerToken(Provider $provider, string $bearerToken): ?string;

	/**
	 * Get the user attributes from the token
	 * This is needed to check if the user is part of a whitelisted group when validating a bearer token
	 *
	 * @param Provider $provider
	 * @param string $bearerToken
	 * @return object
	 */
	public function getUserAttributes(Provider $provider, string $bearerToken): object;

	/**
	 * Selects the provisioning strategy for this validation method.
	 * This is used, when auto_provision and bearerProvisioning are activated.
	 *
	 * @return string the class name of the provisioning strategy
	 */
	public function getProvisioningStrategy(): string;
}
