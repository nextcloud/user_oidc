<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;

class NetworkClient {

	private IClient $client;

	public function __construct(
		IClientService $clientService,
		private IConfig $config,
	) {
		$this->client = $clientService->newClient();
	}

	private function processOptions(array $options): array {
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		if (isset($oidcSystemConfig['disable_certificate_verification']) && $oidcSystemConfig['disable_certificate_verification'] === true) {
			$options['verify'] = false;
		}
		return $options;
	}

	public function get(string $uri, array $options = []): IResponse {
		return $this->client->get($uri, $this->processOptions($options));
	}

	public function post(string $uri, array $options = []): IResponse {
		return $this->client->post($uri, $this->processOptions($options));
	}
}
