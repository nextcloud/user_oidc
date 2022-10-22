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

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class DiscoveryService {
	public const INVALIDATE_DISCOVERY_CACHE_AFTER_SECONDS = 3600;
	public const INVALIDATE_JWKS_CACHE_AFTER_SECONDS = 3600;

	/** @var LoggerInterface */
	private $logger;

	/** @var IClientService */
	private $clientService;

	/** @var ProviderService */
	private $providerService;

	/** @var ICache */
	private $cache;

	public function __construct(LoggerInterface $logger, IClientService $clientService, ProviderService $providerService, ICacheFactory $cacheFactory) {
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->providerService = $providerService;
		$this->cache = $cacheFactory->createDistributed('user_oidc');
	}

	public function obtainDiscovery(Provider $provider): array {
		$cacheKey = 'discovery-' . $provider->getId();
		$cachedDiscovery = $this->cache->get($cacheKey);
		if ($cachedDiscovery === null) {
			$url = $provider->getDiscoveryEndpoint();
			$this->logger->debug('Obtaining discovery endpoint: ' . $url);

			$client = $this->clientService->newClient();
			$response = $client->get($url);
			$cachedDiscovery = $response->getBody();
			$this->cache->set($cacheKey, $cachedDiscovery, self::INVALIDATE_DISCOVERY_CACHE_AFTER_SECONDS);
		}

		return json_decode($cachedDiscovery, true, 512, JSON_THROW_ON_ERROR);
	}

	public function obtainJWK(Provider $provider): array {
		$lastJwksRefresh = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP);
		if ($lastJwksRefresh !== '' && (int) $lastJwksRefresh > time() - self::INVALIDATE_JWKS_CACHE_AFTER_SECONDS) {
			$rawJwks = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE);
			$rawJwks = json_decode($rawJwks, true);
			$jwks = JWK::parseKeySet($rawJwks);
		} else {
			$discovery = $this->obtainDiscovery($provider);
			$client = $this->clientService->newClient();
			$responseBody = $client->get($discovery['jwks_uri'])->getBody();
			$result = json_decode($responseBody, true);
			$jwks = JWK::parseKeySet($result);
			// cache jwks
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE, $responseBody);
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, strval(time()));
		}

		$this->logger->debug('Parsed the jwks');
		return $jwks;
	}

	/**
	 * @param string $authorizationEndpoint
	 * @param array $extraGetParameters
	 * @return string
	 */
	public function buildAuthorizationUrl(string $authorizationEndpoint, array $extraGetParameters = []): string {
		$parsedUrl = parse_url($authorizationEndpoint);

		$urlWithoutParams =
			(isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '')
			. ($parsedUrl['host'] ?? '')
			. (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
			. ($parsedUrl['path'] ?? '');

		$queryParams = $extraGetParameters;
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $parsedQueryParams);
			$queryParams = array_merge($queryParams, $parsedQueryParams);
		}

		// sanitize everything before the query parameters
		// and trust http_build_query to sanitize the query parameters
		return htmlentities(filter_var($urlWithoutParams, FILTER_SANITIZE_URL), ENT_QUOTES)
			. (empty($queryParams) ? '' : '?' . http_build_query($queryParams));
	}
}
