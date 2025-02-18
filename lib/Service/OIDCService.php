<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\Provider;
use OCP\Http\Client\IClientService;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

class OIDCService {

	public function __construct(
		private DiscoveryService $discoveryService,
		private LoggerInterface $logger,
		private IClientService $clientService,
		private ICrypto $crypto,
	) {
	}

	public function userinfo(Provider $provider, string $accessToken): array {
		$url = $this->discoveryService->obtainDiscovery($provider)['userinfo_endpoint'] ?? null;
		if ($url === null) {
			return [];
		}

		$client = $this->clientService->newClient();
		$this->logger->debug('Fetching user info endpoint');
		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $accessToken,
			],
		];
		try {
			return json_decode($client->get($url, $options)->getBody(), true);
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

		$client = $this->clientService->newClient();
		$this->logger->debug('Fetching user info endpoint');
		$options = [
			'headers' => [
				'Authorization' => base64_encode($provider->getClientId() . ':' . $providerClientSecret),
			],
			'body' => [
				'token' => $accessToken,
			],
		];
		try {
			return json_decode($client->post($url, $options)->getBody(), true);
		} catch (Throwable $e) {
			return [];
		}
	}
}
