<?php

namespace OCA\UserOIDC\User\Provisioning;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

class SelfEncodedTokenProvisioning implements IProvisioningStrategy {

	/** @var ProvisioningService */
	private $provisioningService;

	/** @var DiscoveryService */
	private $discoveryService;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(ProvisioningService $provisioningService, DiscoveryService $discoveryService, LoggerInterface $logger) {
		$this->provisioningService = $provisioningService;
		$this->discoveryService = $discoveryService;
		$this->logger = $logger;
	}

	public function provisionUser(Provider $provider, string $sub, string $bearerToken): ?IUser {
		JWT::$leeway = 60;
		try {
			$payload = JWT::decode($bearerToken, $this->discoveryService->obtainJWK($provider), array_keys(JWT::$supported_algs));
		} catch (Throwable $e) {
			$this->logger->error('Impossible to decode OIDC token:' . $e->getMessage());
			return null;
		}

		return $this->provisioningService->provisionUser($sub, $provider->getId(), $payload);
	}
}
