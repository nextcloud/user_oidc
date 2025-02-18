<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

	private ICache $cache;

	public function __construct(
		private LoggerInterface $logger,
		private IClientService $clientService,
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

			$client = $this->clientService->newClient();
			$response = $client->get($url);
			$cachedDiscovery = $response->getBody();

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
