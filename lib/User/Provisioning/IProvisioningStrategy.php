<?php

namespace OCA\UserOIDC\User\Provisioning;

use OCA\UserOIDC\Db\Provider;
use OCP\IUser;

interface IProvisioningStrategy {

	/**
	 * Defines a way to provision a user.
	 *
	 * @param Provider $provider
	 * @param string $tokenUserId
	 * @param string $bearerToken
	 * @param IUser|null $userFromOtherBackend
	 * @return IUser|null
	 */
	public function provisionUser(Provider $provider, string $tokenUserId, string $bearerToken, ?IUser $userFromOtherBackend): ?IUser;
}
