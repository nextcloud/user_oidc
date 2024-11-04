<?php
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
