<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\User\Validator;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\User\Provisioning\SelfEncodedTokenProvisioning;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class SelfEncodedValidator implements IBearerTokenValidator {

	public function __construct(
		private DiscoveryService $discoveryService,
		private ProvisioningService $provisioningService,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private IConfig $config,
	) {
	}

	public function isValidBearerToken(Provider $provider, string $bearerToken): ?string {
		/** @var ProviderService $providerService */
		$providerService = \OC::$server->get(ProviderService::class);
		$providerId = $provider->getId();
		$uidAttribute = $providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, ProviderService::SETTING_MAPPING_UID_DEFAULT);

		// try to decode the bearer token
		JWT::$leeway = 60;
		try {
			$jwks = $this->discoveryService->obtainJWK($provider, $bearerToken);
			$payload = JWT::decode($bearerToken, $jwks);
		} catch (Throwable $e) {
			$this->logger->debug('Impossible to decode OIDC token:' . $e->getMessage());
			return null;
		}

		// check if the token has expired
		if ($payload->exp < $this->timeFactory->getTime()) {
			$this->logger->debug('OIDC token has expired');
			return null;
		}

		$discovery = $this->discoveryService->obtainDiscovery($provider);
		if ($payload->iss !== $discovery['issuer']) {
			$this->logger->debug('This token is issued by the wrong issuer, it does not match the one from the discovery endpoint');
			return null;
		}

		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		// ref https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
		$checkAudience = !isset($oidcSystemConfig['selfencoded_bearer_validation_audience_check'])
			|| !in_array($oidcSystemConfig['selfencoded_bearer_validation_audience_check'], [false, 'false', 0, '0'], true);
		$providerClientId = $provider->getClientId();
		if ($checkAudience) {
			$tokenAudience = $payload->aud;
			if (
				(is_string($tokenAudience) && $tokenAudience !== $providerClientId)
					|| (is_array($tokenAudience) && !in_array($providerClientId, $tokenAudience, true))
			) {
				$this->logger->debug('This token is not for us, the audience does not match the client ID');
				return null;
			}
		}

		// find the user ID
		$uid = $this->provisioningService->getClaimValue($payload, $uidAttribute, $providerId);
		return $uid ?: null;
	}

	public function getProvisioningStrategy(): string {
		return SelfEncodedTokenProvisioning::class;
	}
}
