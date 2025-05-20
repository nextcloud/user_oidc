<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\User\Provisioning;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

class SelfEncodedTokenProvisioning implements IProvisioningStrategy {

	public function __construct(
		private ProvisioningService $provisioningService,
		private DiscoveryService $discoveryService,
		private LoggerInterface $logger,
	) {
	}

	public function provisionUser(Provider $provider, string $tokenUserId, string $bearerToken, ?IUser $userFromOtherBackend): ?IUser {
		JWT::$leeway = 60;
		try {
			$jwks = $this->discoveryService->obtainJWK($provider, $bearerToken);
			$payload = JWT::decode($bearerToken, $jwks);
		} catch (Throwable $e) {
			$this->logger->error('Impossible to decode OIDC token:' . $e->getMessage());
			return null;
		}

		$provisioningResult = $this->provisioningService->provisionUser($tokenUserId, $provider->getId(), $payload, $userFromOtherBackend);
		return $provisioningResult['user'];
	}
}
