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
use OCA\UserOIDC\Service\ProvisioningService;

class UserInfoValidator implements IBearerTokenValidator {

	public function __construct(
		private OIDCService $userInfoService,
		private ProviderService $providerService,
		private ProvisioningService $provisioningService,
	) {
	}

	public function isValidBearerToken(Provider $provider, string $bearerToken): ?string {
		$userInfo = $this->userInfoService->userinfo($provider, $bearerToken);
		$providerId = $provider->getId();
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, ProviderService::SETTING_MAPPING_UID_DEFAULT);
		// find the user ID
		$uid = $this->provisioningService->getClaimValue($userInfo, $uidAttribute, $providerId);
		return $uid ?: null;
	}

	public function getProvisioningStrategy(): string {
		// TODO implement provisioning over user info endpoint
		return '';
	}
}
