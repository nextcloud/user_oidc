<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Helper;

use OCA\UserOIDC\Vendor\Id4me\RP\HttpClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

class HttpClientHelper implements HttpClient {

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	public function get($url, array $headers = [], array $options = []) {
		if ($this->shouldDisableSSLVerification()) {
			$options['verify'] = false;
		}
		if ($this->shouldAllowLocalAddresses()) {
			$options['nextcloud']['allow_local_address'] = true;
		}

		return $this->clientService->newClient()->get($url, $options)->getBody();
	}

	public function post($url, $body, array $headers = []) {
		$options = [
			'headers' => $headers,
			'body' => $body,
		];

		if ($this->shouldDisableSSLVerification()) {
			$options['verify'] = false;
		}
		if ($this->shouldAllowLocalAddresses()) {
			$options['nextcloud']['allow_local_address'] = true;
		}

		return $this->clientService->newClient()->post($url, $options)->getBody();
	}

	private function shouldDisableSSLVerification(): bool {
		if ($this->config->getSystemValueBool('debug', false)) {
			return true;
		}

		return $this->isOidcConfigFlagEnabled('httpclient.allowselfsigned');
	}

	/**
	 * Allow requests to local/private addresses (e.g. a self-hosted IdP on the LAN,
	 * split DNS or the same Docker network) without enabling the server-wide
	 * allow_local_remote_servers option.
	 */
	private function shouldAllowLocalAddresses(): bool {
		return $this->isOidcConfigFlagEnabled('httpclient.allowlocal');
	}

	private function isOidcConfigFlagEnabled(string $key): bool {
		$oidcConfig = $this->config->getSystemValue('user_oidc', []);
		if (!is_array($oidcConfig) || !isset($oidcConfig[$key])) {
			return false;
		}

		$value = $oidcConfig[$key];

		return !($value === false
			|| $value === 'false'
			|| $value === 0
			|| $value === '0');
	}
}
