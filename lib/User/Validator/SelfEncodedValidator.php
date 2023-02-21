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
use Psr\Log\LoggerInterface;
use Throwable;

class SelfEncodedValidator implements IBearerTokenValidator {

	/** @var DiscoveryService */
	private $discoveryService;
	/** @var LoggerInterface */
	private $logger;
	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(DiscoveryService $discoveryService, LoggerInterface $logger, ITimeFactory $timeFactory) {
		$this->discoveryService = $discoveryService;
		$this->logger = $logger;
		$this->timeFactory = $timeFactory;
	}

	public function isValidBearerToken(Provider $provider, string $bearerToken): ?string {
		/** @var ProviderService $providerService */
		$providerService = \OC::$server->get(ProviderService::class);
		$uidAttribute = $providerService->getSetting($provider->getId(), ProviderService::SETTING_MAPPING_UID, ProviderService::SETTING_MAPPING_UID_DEFAULT);

		// try to decode the bearer token
		JWT::$leeway = 60;
		try {
			$payload = JWT::decode($bearerToken, $this->discoveryService->obtainJWK($provider), array_keys(JWT::$supported_algs));
		} catch (Throwable $e) {
			$this->logger->error('Impossible to decode OIDC token:' . $e->getMessage());
			return null;
		}

		// check if the token has expired
		if ($payload->exp < $this->timeFactory->getTime()) {
			$this->logger->error('OIDC token has expired');
			return null;
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
