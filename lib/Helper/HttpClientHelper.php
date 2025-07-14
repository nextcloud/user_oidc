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

class HttpClientHelper implements HttpClient
{

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	public function get($url, array $options = [])
	{
		$client = $this->clientService->newClient();

		if ($this->config->getSystemValue('httpclient.allowselfsigned', false)) {
			$options['verify'] = false;
		}

		return $client->get($url, $options)->getBody();
	}

	public function post($url, $body, array $headers = [])
	{
		$client = $this->clientService->newClient();

		$options = [
			'headers' => $headers,
			'body' => $body,
		];

		if ($this->config->getSystemValue('httpclient.allowselfsigned', false)) {
			$options['verify'] = false;
		}

		return $client->post($url, $options)->getBody();
	}
}
