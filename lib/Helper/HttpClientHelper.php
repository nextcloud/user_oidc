<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Helper;

use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Vendor\Id4me\RP\HttpClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

class HttpClientHelper implements HttpClient {

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private ProviderMapper $providerMapper,
	) {
	}

	public function get($url, array $headers = [], array $options = []) {
		if ($this->shouldDisableSSLVerification()) {
			$options['verify'] = false;
		}
		if ($this->isAllowedLocalProvider($url)) {
			$options['nextcloud']['allow_local_address'] = true;
		}

		return $this->clientService->newClient()->get($url, $options)->getBody();
	}

	/**
	 * Fetch the discovery endpoint of a provider that an admin is creating or updating.
	 * Its host is not a configured provider yet, so with 'httpclient.allowlocalprovider'
	 * set this request may reach a local address whatever its host.
	 * Only use it for URLs entered by an admin.
	 */
	public function getProviderSetupDiscovery(string $url) {
		$options = [];
		if ($this->isOidcConfigEnabled('httpclient.allowlocalprovider')) {
			$options['nextcloud']['allow_local_address'] = true;
		}

		return $this->get($url, [], $options);
	}

	public function post($url, $body, array $headers = []) {
		$options = [
			'headers' => $headers,
			'body' => $body,
		];

		if ($this->shouldDisableSSLVerification()) {
			$options['verify'] = false;
		}
		if ($this->isAllowedLocalProvider($url)) {
			$options['nextcloud']['allow_local_address'] = true;
		}

		return $this->clientService->newClient()->post($url, $options)->getBody();
	}

	private function shouldDisableSSLVerification(): bool {
		if ($this->config->getSystemValueBool('debug', false)) {
			return true;
		}

		return $this->isOidcConfigEnabled('httpclient.allowselfsigned');
	}

	private function isOidcConfigEnabled(string $key): bool {
		$oidcConfig = $this->config->getSystemValue('user_oidc', []);
		return isset($oidcConfig[$key])
			&& !in_array($oidcConfig[$key], [false, 'false', 0, '0'], true);
	}

	/**
	 * With 'httpclient.allowlocalprovider' set, requests to the host of a configured
	 * provider's discovery endpoint may reach a local address (an identity provider on
	 * the local network), without 'allow_local_remote_servers' allowing it for the
	 * whole server. Requests to other hosts, such as an ID4me authority, keep the protection.
	 */
	private function isAllowedLocalProvider(string $url): bool {
		if (!$this->isOidcConfigEnabled('httpclient.allowlocalprovider')) {
			return false;
		}

		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}

		foreach ($this->providerMapper->getProviders() as $provider) {
			$providerHost = parse_url($provider->getDiscoveryEndpoint() ?? '', PHP_URL_HOST);
			if (is_string($providerHost) && strcasecmp($providerHost, $host) === 0) {
				return true;
			}
		}

		return false;
	}
}
