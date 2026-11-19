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
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

class OIDCService {
	public const USERINFO_CACHE_TTL_SECONDS = 300;

	private ?ICache $userInfoCache = null;

	public function __construct(
		private DiscoveryService $discoveryService,
		private LoggerInterface $logger,
		private HttpClientHelper $clientService,
		private ICrypto $crypto,
		?ICacheFactory $cacheFactory = null,
	) {
		if ($cacheFactory !== null) {
			$this->userInfoCache = $cacheFactory->createDistributed('user_oidc-userinfo');
		}
	}

	public function userinfo(Provider $provider, string $accessToken): array {
		$cacheKey = hash('sha256', (string)$provider->getId() . "\0" . $accessToken);
		if ($this->userInfoCache !== null) {
			$cached = $this->userInfoCache->get($cacheKey);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$userInfo = $this->fetchUserInfo($provider, $accessToken);
		if ($userInfo !== [] && $this->userInfoCache !== null) {
			$this->userInfoCache->set($cacheKey, $userInfo, self::USERINFO_CACHE_TTL_SECONDS);
		}
		return $userInfo;
	}

	private function fetchUserInfo(Provider $provider, string $accessToken): array {
		$url = $this->discoveryService->obtainDiscovery($provider)['userinfo_endpoint'] ?? null;
		if ($url === null) {
			return [];
		}

		$this->logger->debug('[UserInfo] Fetching user info endpoint', ['userinfo_url' => $url]);
		$options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $accessToken,
			],
		];

		try {
			$userInfoResponse = $this->clientService->get($url, [], $options);
		} catch (Throwable $e) {
			$this->logger->warning('[UserInfo] Failed to fetch user info endpoint', ['exception' => $e]);
			return [];
		}

		try {
			return json_decode($userInfoResponse, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			$this->logger->debug('[UserInfo] The response is not JSON');
		}

		JWT::$leeway = 60;
		try {
			$jwks = $this->discoveryService->obtainJWK($provider, $userInfoResponse);
			$payload = JWT::decode($userInfoResponse, $jwks);
			$arrayPayload = json_decode(json_encode($payload), true);
			$this->logger->debug('[UserInfo] Decoded the JWT response', ['decoded_userinfo_response' => $arrayPayload]);
			return $arrayPayload;
		} catch (Throwable $e) {
			$this->logger->warning('[UserInfo] Failed to decode the response as a JWT', ['exception' => $e]);
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
