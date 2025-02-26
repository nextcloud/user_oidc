<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Helper;

use OCA\UserOIDC\Service\NetworkService;

require_once __DIR__ . '/../../vendor/autoload.php';
use Id4me\RP\HttpClient;

class HttpClientHelper implements HttpClient {

	public function __construct(
		private NetworkService $networkService,
	) {
	}

	public function get($url, array $headers = []) {
		$client = $this->networkService->newClient();

		return $client->get($url, [
			'headers' => $headers,
		])->getBody();
	}

	public function post($url, $body, array $headers = []) {
		$client = $this->networkService->newClient();

		return $client->post($url, [
			'headers' => $headers,
			'body' => $body,
		])->getBody();
	}
}
