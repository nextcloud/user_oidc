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
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class DiscoveryService {
	public const INVALIDATE_DISCOVERY_CACHE_AFTER_SECONDS = 3600;
	public const INVALIDATE_JWKS_CACHE_AFTER_SECONDS = 3600;

	/**
	 *
	 * See https://www.imsglobal.org/spec/security/v1p1#approved-jwt-signing-algorithms.
	 * @var string[]
	 */
	private const SUPPORTED_JWK_ALGS = [
		'RS256' => 'RSA',
		'RS384' => 'RSA',
		'RS512' => 'RSA',
		'ES256' => 'EC',
		'ES384' => 'EC',
		'ES512' => 'EC'
	];

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

			// Manipulate the response with the custom endpoint url
			$endpointData = json_encode($this->providerService->getProviderWithSettings($provider->getId()));
			$endpointData = json_decode($endpointData)->endSessionEndpoint;
			if($endpointData) {
				$discoveryData = json_decode($cachedDiscovery);
				$discoveryData->end_session_endpoint = $endpointData;
				$cachedDiscovery = json_encode($discoveryData);
			}
			
			$this->cache->set($cacheKey, $cachedDiscovery, self::INVALIDATE_DISCOVERY_CACHE_AFTER_SECONDS);
		}

		return json_decode($cachedDiscovery, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param Provider $provider
	 * @param string $tokenToDecode This is used to potentially fix the missing alg in
	 * @return array
	 * @throws \JsonException
	 */
	public function obtainJWK(Provider $provider, string $tokenToDecode): array {
		$lastJwksRefresh = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP);
		if ($lastJwksRefresh !== '' && (int) $lastJwksRefresh > time() - self::INVALIDATE_JWKS_CACHE_AFTER_SECONDS) {
			$rawJwks = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE);
			$rawJwks = json_decode($rawJwks, true);
		} else {
			$discovery = $this->obtainDiscovery($provider);
			$client = $this->clientService->newClient();
			$responseBody = $client->get($discovery['jwks_uri'])->getBody();
			$rawJwks = json_decode($responseBody, true);
			// cache jwks
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE, $responseBody);
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, strval(time()));
		}

		$fixedJwks = $this->fixJwksAlg($rawJwks, $tokenToDecode);
		$jwks = JWK::parseKeySet($fixedJwks, 'RS256');
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

	/**
	 * Inspired by https://github.com/snake/moodle/compare/880462a1685...MDL-77077-master
	 *
	 * @param array $jwks
	 * @param string $jwt
	 * @return array
	 * @throws \Exception
	 */
	private function fixJwksAlg(array $jwks, string $jwt): array {
		$jwtParts = explode('.', $jwt);
		$jwtHeader = json_decode(JWT::urlsafeB64Decode($jwtParts[0]), true);
		if (!isset($jwtHeader['kid'])) {
			throw new \Exception('Error: kid must be provided in JWT header.');
		}

		foreach ($jwks['keys'] as $index => $key) {
			// Only fix the key being referred to in the JWT.
			if ($jwtHeader['kid'] != $key['kid']) {
				continue;
			}

			// Only fix the key if the alg is missing.
			if (!empty($key['alg'])) {
				continue;
			}

			// The header alg must match the key type (family) specified in the JWK's kty.
			if (!isset(self::SUPPORTED_JWK_ALGS[$jwtHeader['alg']]) || self::SUPPORTED_JWK_ALGS[$jwtHeader['alg']] !== $key['kty']) {
				throw new \Exception('Error: Alg specified in the JWT header is incompatible with the JWK key type');
			}

			$jwks['keys'][$index]['alg'] = $jwtHeader['alg'];
		}

		return $jwks;
	}
}
