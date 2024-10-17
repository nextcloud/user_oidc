<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace OCA\UserOIDC\User\Validator;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProviderService;

class UserInfoValidator implements IBearerTokenValidator {

	public function __construct(
		private OIDCService $userInfoService,
		private ProviderService $providerService,
	) {
	}

	public function isValidBearerToken(Provider $provider, string $bearerToken): ?string {
		$userInfo = $this->userInfoService->userinfo($provider, $bearerToken);
		$uidAttribute = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_MAPPING_UID, ProviderService::SETTING_MAPPING_UID_DEFAULT);
		if (!isset($userInfo[$uidAttribute])) {
			return null;
		}

		return $userInfo[$uidAttribute];
	}

	public function getProvisioningStrategy(): string {
		// TODO implement provisioning over user info endpoint
		return '';
	}
}
