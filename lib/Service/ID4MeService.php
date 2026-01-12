<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class ID4MeService {

	private ICache $cache;
	private IClient $client;

	public function __construct(
		private IConfig $config,
		IClientService $clientService,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private DiscoveryService $discoveryService,
	) {
		$this->cache = $cacheFactory->createDistributed('user_oidc');
		$this->client = $clientService->newClient();
	}

	public function setID4ME(bool $enabled): void {
		$this->config->setAppValue(Application::APP_ID, 'id4me_enabled', $enabled ? '1' : '0');
	}

	public function getID4ME(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'id4me_enabled', '0') === '1';
	}

	public function obtainJWK(string $jwkUri, string $tokenToDecode, bool $useCache = true): array {
		$cacheKey = 'jwks-' . $jwkUri;
		$cachedJwks = $this->cache->get($cacheKey);
		if ($cachedJwks !== null && $useCache) {
			$rawJwks = json_decode($cachedJwks, true, flags: JSON_THROW_ON_ERROR);
			$this->logger->debug('[ID4ME-obtainJWK] jwks cache content', ['jwks_cache' => $rawJwks]);
		} else {
			$responseBody = (string)$this->client->get($jwkUri)->getBody();
			$rawJwks = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
			$this->logger->debug('[ID4ME-obtainJWK] getting fresh jwks', ['jwks' => $rawJwks]);
			$this->cache->set($cacheKey, $responseBody, DiscoveryService::INVALIDATE_JWKS_CACHE_AFTER_SECONDS);
		}

		$fixedJwks = $this->discoveryService->fixJwksAlg($rawJwks, $tokenToDecode);
		$this->logger->debug('[ID4ME-obtainJWK] fixed jwks', ['fixed_jwks' => $fixedJwks]);
		$jwks = JWK::parseKeySet($fixedJwks, 'RS256');
		$this->logger->debug('Parsed the jwks');
		return $jwks;
	}
}
