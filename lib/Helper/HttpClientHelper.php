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

		return $this->clientService->newClient()->post($url, $options)->getBody();
	}

	private function shouldDisableSSLVerification(): bool {
		if ($this->config->getSystemValueBool('debug', false)) {
			return true;
		}

		$oidcConfig = $this->config->getSystemValue('user_oidc', []);
		if (!isset($oidcConfig['httpclient.allowselfsigned'])) {
			return false;
		}

		$allowSelfSigned = $oidcConfig['httpclient.allowselfsigned'];

		return !($allowSelfSigned === false
			|| $allowSelfSigned === 'false'
			|| $allowSelfSigned === 0
			|| $allowSelfSigned === '0');
	}
}
