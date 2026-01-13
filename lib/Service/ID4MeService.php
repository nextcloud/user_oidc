<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class ID4MeService {

	/** @var IConfig */
	private $config;

	/** @var \OCP\ICache */
	private $cache;

	/** @var \OCP\Http\Client\IClient */
	private $client;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IConfig $config,
		IClientService $clientService,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger,
	) {
		$this->config = $config;
		$this->cache = $cacheFactory->createDistributed('user_oidc');
		$this->client = $clientService->newClient();
		$this->logger = $logger;
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
			$rawJwks = json_decode($cachedJwks, true, 512, JSON_THROW_ON_ERROR);
			$this->logger->debug('[ID4ME-obtainJWK] jwks cache content', ['jwks_cache' => $rawJwks]);
		} else {
			$responseBody = (string)$this->client->get($jwkUri)->getBody();
			$rawJwks = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
			$this->logger->debug('[ID4ME-obtainJWK] getting fresh jwks', ['jwks' => $rawJwks]);
			$this->cache->set($cacheKey, $responseBody, DiscoveryService::INVALIDATE_JWKS_CACHE_AFTER_SECONDS);
		}

		$this->logger->debug('[ID4ME-obtainJWK] jwks', ['jwks' => $rawJwks]);
		$jwks = JWK::parseKeySet($rawJwks);
		$this->logger->debug('Parsed the jwks');
		return $jwks;
	}
}
