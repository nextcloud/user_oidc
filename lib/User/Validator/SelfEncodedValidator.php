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
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\User\Provisioning\SelfEncodedTokenProvisioning;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class SelfEncodedValidator implements IBearerTokenValidator {

	/** @var DiscoveryService */
	private $discoveryService;
	/** @var LoggerInterface */
	private $logger;
	/** @var ITimeFactory */
	private $timeFactory;
	/** @var IConfig */
	private $config;

	public function __construct(
		DiscoveryService $discoveryService,
		LoggerInterface $logger,
		ITimeFactory $timeFactory,
		IConfig $config
	) {
		$this->discoveryService = $discoveryService;
		$this->logger = $logger;
		$this->timeFactory = $timeFactory;
		$this->config = $config;
	}

	public function isValidBearerToken(Provider $provider, string $bearerToken): ?string {
		/** @var ProviderService $providerService */
		$providerService = \OC::$server->get(ProviderService::class);
		$uidAttribute = $providerService->getSetting($provider->getId(), ProviderService::SETTING_MAPPING_UID, ProviderService::SETTING_MAPPING_UID_DEFAULT);

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
		$checkAudience = !isset($oidcSystemConfig['selfencoded_bearer_validation_audience_check'])
			|| !in_array($oidcSystemConfig['selfencoded_bearer_validation_audience_check'], [false, 'false', 0, '0'], true);
		if ($checkAudience) {
			$tokenAudience = $payload->aud;
			$providerClientId = $provider->getClientId();
			if (
				(is_string($tokenAudience) && $tokenAudience !== $providerClientId)
					|| (is_array($tokenAudience) && !in_array($providerClientId, $tokenAudience, true))
			) {
				$this->logger->debug('This token is not for us, the audience does not match the client ID');
				return null;
			}

			// If the ID Token contains multiple audiences, the Client SHOULD verify that an azp Claim is present.
			// If an azp (authorized party) Claim is present, the Client SHOULD verify that its client_id is the Claim Value.
			if (is_array($tokenAudience) && count($tokenAudience) > 1) {
				if (isset($payload->azp)) {
					if ($payload->azp !== $providerClientId) {
						$this->logger->debug('This token is not for us, authorized party (azp) is different than the client ID');
						return null;
					}
				} else {
					$this->logger->debug('Multiple audiences but no authorized party (azp) in the id token');
					return null;
				}
			}
		}

		// find the user ID
		if (!isset($payload->{$uidAttribute})) {
			return null;
		}

		return $payload->{$uidAttribute};
	}

	public function getProvisioningStrategy(): string {
		return SelfEncodedTokenProvisioning::class;
	}
}
