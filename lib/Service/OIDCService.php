<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
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
			return json_decode($this->clientService->get($url, [], $options), true);
		} catch (Throwable $e) {
			return [];
		}
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
