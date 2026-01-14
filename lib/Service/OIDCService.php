<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

class OIDCService {

	public function __construct(
		private DiscoveryService $discoveryService,
		private LoggerInterface $logger,
		private HttpClientHelper $clientService,
		private ICrypto $crypto,
	) {
	}

	public function userinfo(Provider $provider, string $accessToken): array {
		$url = $this->discoveryService->obtainDiscovery($provider)['userinfo_endpoint'] ?? null;
		if ($url === null) {
			return [];
		}

		$this->logger->debug('Fetching user info endpoint');
		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $accessToken,
			],
		];

		try {
			$userInfoResponse = $this->clientService->get($url, [], $options);
		} catch (Throwable $e) {
			$this->logger->error('Request to the userinfo endpoint failed', ['exception' => $e]);
			return [];
		}

		// try to decode it like a JSON string
		try {
			return json_decode($userInfoResponse, true);
		} catch (Throwable) {
			$this->logger->debug('The userinfo response is not JSON');
		}

		// try to decode it like a JWT token
		JWT::$leeway = 60;
		try {
			$jwks = $this->discoveryService->obtainJWK($provider, $userInfoResponse);
			$payload = JWT::decode($userInfoResponse, $jwks);
			$arrayPayload = json_decode(json_encode($payload), true);
			$this->logger->debug('JWT Decoded user info response', ['decoded_userinfo_response' => $arrayPayload]);
			return $arrayPayload;
		} catch (Throwable $e) {
			$this->logger->debug('Treating the userinfo response as a JWT token. Impossible to decode it:' . $e->getMessage());
		}

		return [];
	}

	public function introspection(Provider $provider, string $accessToken): array {
		try {
			$providerClientSecret = $this->crypto->decrypt($provider->getClientSecret());
		} catch (\Exception $e) {
			$this->logger->error('Failed to decrypt the client secret', ['exception' => $e]);
			return [];
		}
		$url = $this->discoveryService->obtainDiscovery($provider)['introspection_endpoint'] ?? null;
		if ($url === null) {
			return [];
		}

		$this->logger->debug('Fetching user info endpoint');

		try {
			$body = $this->clientService->post(
				$url,
				['token' => $accessToken],
				[
					'Authorization' => base64_encode($provider->getClientId() . ':' . $providerClientSecret),
				]
			);

			return json_decode($body, true);
		} catch (Throwable $e) {
			return [];
		}
	}
}
