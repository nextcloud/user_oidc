<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
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

	private ICache $cache;

	public function __construct(
		private LoggerInterface $logger,
		private HttpClientHelper $clientService,
		private ProviderService $providerService,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('user_oidc');
	}

	public function obtainDiscovery(Provider $provider): array {
		$cacheKey = 'discovery-' . $provider->getDiscoveryEndpoint();
		$cachedDiscovery = $this->cache->get($cacheKey);
		if ($cachedDiscovery === null) {
			$url = $provider->getDiscoveryEndpoint();
			$this->logger->debug('Obtaining discovery endpoint: ' . $url);

			$cachedDiscovery = $this->clientService->get($url);

			$this->cache->set($cacheKey, $cachedDiscovery, self::INVALIDATE_DISCOVERY_CACHE_AFTER_SECONDS);
		}

		return json_decode($cachedDiscovery, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param Provider $provider
	 * @param string $tokenToDecode This is used to potentially fix the missing alg in
	 * @param bool $useCache
	 * @return array
	 * @throws \JsonException
	 */
	public function obtainJWK(Provider $provider, string $tokenToDecode, bool $useCache = true): array {
		$lastJwksRefresh = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP);
		if ($lastJwksRefresh !== '' && $useCache && (int)$lastJwksRefresh > time() - self::INVALIDATE_JWKS_CACHE_AFTER_SECONDS) {
			$rawJwks = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE);
			$rawJwks = json_decode($rawJwks, true);
			$this->logger->debug('[obtainJWK] jwks cache content', ['jwks_cache' => $rawJwks]);
		} else {
			$discovery = $this->obtainDiscovery($provider);
			$responseBody = $this->clientService->get($discovery['jwks_uri']);
			$rawJwks = json_decode($responseBody, true);
			$this->logger->debug('[obtainJWK] getting fresh jwks', ['jwks' => $rawJwks]);
			// cache jwks
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE, $responseBody);
			$this->logger->debug('[obtainJWK] setting cache', ['jwks_cache' => $responseBody]);
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, strval(time()));
		}

		$fixedJwks = $this->fixJwksAlg($rawJwks, $tokenToDecode);
		$this->logger->debug('[obtainJWK] fixed jwks', ['fixed_jwks' => $fixedJwks]);
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

		$urlWithoutParams
			= (isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '')
			. ($parsedUrl['host'] ?? '')
			. (isset($parsedUrl['port']) ? ':' . strval($parsedUrl['port']) : '')
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
		$kid = $jwtHeader['kid'] ?? null;

		foreach ($jwks['keys'] as $index => $key) {
			// Skip if alg is already set
			if (!empty($key['alg'])) {
				continue;
			}

			// Match by kid if present, or by alg and kty if kid is missing
			if ($kid !== null && $jwtHeader['kid'] != $key['kid']) {
				continue;
			}

			// Validate algorithm compatibility
			// Skip incompatible keys
			if (!isset(self::SUPPORTED_JWK_ALGS[$jwtHeader['alg']]) || self::SUPPORTED_JWK_ALGS[$jwtHeader['alg']] !== $key['kty']) {
				continue;
			}

			// Set alg for the matching key
			$jwks['keys'][$index]['alg'] = $jwtHeader['alg'];

			return $jwks; // Return early after fixing the first match
		}

		throw new \Exception('Error: No matching key found in JWKS for alg ' . ($jwtHeader['alg'] ?? 'unknown'));
	}
}
