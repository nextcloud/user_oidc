<?php

namespace OCA\UserOIDC\User\Validator;

use OCA\UserOIDC\Db\Provider;
use OCP\IUser;

interface IProvisioningStrategy {

	/**
	 * TODO
	 * @param Provider $provider
	 * @param string $userId
	 * @param string $bearerToken
	 * @return IUser|null
	 */
	public function provisionUser(Provider $provider, string $userId, string $bearerToken): ?IUser;
}
