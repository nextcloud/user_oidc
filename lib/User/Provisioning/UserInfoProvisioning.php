<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\User\Provisioning;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisioning strategy for opaque bearer tokens.
 *
 * Counterpart of SelfEncodedTokenProvisioning: an opaque token carries no
 * claims, so the payload is fetched from the provider's `userinfo` endpoint
 * instead of being decoded from the token itself. Without this strategy the
 * validator has nothing to return for getProvisioningStrategy() and users
 * authenticated with an opaque token are never provisioned at all.
 *
 * The access token was already validated by UserInfoValidator before this
 * code runs, so a failure here is a transport/endpoint failure, not an
 * authorization failure.
 */
class UserInfoProvisioning implements IProvisioningStrategy {

	public function __construct(
		private OIDCService $oidcService,
		private ProvisioningService $provisioningService,
		private LoggerInterface $logger,
	) {
	}

	public function provisionUser(Provider $provider, string $tokenUserId, string $bearerToken, ?IUser $userFromOtherBackend): ?IUser {
		try {
			$userInfo = $this->oidcService->userinfo($provider, $bearerToken);
		} catch (Throwable $e) {
			$this->logger->error('Impossible to get the user info for provisioning: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}

		$provisioningResult = $this->provisioningService->provisionUser(
			$tokenUserId,
			$provider->getId(),
			(object)$userInfo,
			$userFromOtherBackend,
		);

		return $provisioningResult['user'];
	}
}
