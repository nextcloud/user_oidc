<?php

namespace OCA\UserOIDC\User\Provisioning;

use OCA\UserOIDC\Db\Provider;
use OCP\IUser;

interface IProvisioningStrategy {

	/**
	 * Defines a way to provision a user.
	 *
	 * @param Provider $provider
	 * @param string $sub
	 * @param string $bearerToken
	 * @return IUser|null
	 */
	public function provisionUser(Provider $provider, string $sub, string $bearerToken): ?IUser;
}
