<?php
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\User\Validator;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProviderService;
use Psr\Log\LoggerInterface;

class UserInfoValidator implements IBearerTokenValidator {

	/** @var DiscoveryService */
	private $discoveryService;
	/** @var OIDCService */
	private $userInfoService;
	/** @var ProviderService */
	private $providerService;
	/** @var LoggerInterface */
	private $logger;


	public function __construct(DiscoveryService $discoveryService, LoggerInterface $logger, OIDCService $userInfoService, ProviderService $providerService) {
		$this->discoveryService = $discoveryService;
		$this->logger = $logger;
		$this->userInfoService = $userInfoService;
		$this->providerService = $providerService;
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
