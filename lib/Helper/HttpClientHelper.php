<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Helper;

use OCP\IConfig;

require_once __DIR__ . '/../../vendor/autoload.php';
use Id4me\RP\HttpClient;
use OCP\Http\Client\IClientService;

class HttpClientHelper implements HttpClient {

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	public function get($url, array $headers = [], array $options = []) {
		$oidcConfig = $this->config->getSystemValue('junovy_user_oidc', []);

		$client = $this->clientService->newClient();

		$debugModeEnabled = $this->config->getSystemValueBool('debug', false);

		// Check if TLS verify is explicitly set in options (per-provider setting)
		if (!isset($options['verify'])) {
			// Check global config
			if ($debugModeEnabled
				|| (isset($oidcConfig['httpclient.allowselfsigned'])
					&& !in_array($oidcConfig['httpclient.allowselfsigned'], [false, 'false', 0, '0'], true))) {
				$options['verify'] = false;
			}
		}

		return $client->get($url, $options)->getBody();
	}

	public function post($url, $body, array $headers = []) {
		$oidcConfig = $this->config->getSystemValue('junovy_user_oidc', []);
		$client = $this->clientService->newClient();

		$options = [
			'headers' => $headers,
			'body' => $body,
		];

		// Check global config for self-signed certificates
		if (isset($oidcConfig['httpclient.allowselfsigned'])
			&& !in_array($oidcConfig['httpclient.allowselfsigned'], [false, 'false', 0, '0'], true)) {
			$options['verify'] = false;
		}

		return $client->post($url, $options)->getBody();
	}

	/**
	 * POST request with additional options (e.g., TLS verify)
	 *
	 * @param string $url
	 * @param mixed $body
	 * @param array<string, mixed> $headers
	 * @param array{verify?: bool} $options Additional options like 'verify' for TLS
	 * @return string
	 */
	public function postWithOptions($url, $body, array $headers = [], array $options = []): string {
		$oidcConfig = $this->config->getSystemValue('junovy_user_oidc', []);
		$client = $this->clientService->newClient();

		$requestOptions = [
			'headers' => $headers,
			'body' => $body,
		];

		// Merge in provided options (verify key)
		if (isset($options['verify'])) {
			$requestOptions['verify'] = $options['verify'];
		}

		// Check if TLS verify is explicitly set in options (per-provider setting)
		if (!isset($requestOptions['verify'])) {
			// Check global config
			if (isset($oidcConfig['httpclient.allowselfsigned'])
				&& !in_array($oidcConfig['httpclient.allowselfsigned'], [false, 'false', 0, '0'], true)) {
				$requestOptions['verify'] = false;
			}
		}

		$body = $client->post($url, $requestOptions)->getBody();
		if (is_resource($body)) {
			$contents = stream_get_contents($body);
			return $contents !== false ? $contents : '';
		}
		return $body;
	}
}
