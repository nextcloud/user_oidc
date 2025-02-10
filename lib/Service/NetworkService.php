<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;

class NetworkService {

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	public function newClient(): NetworkClient {
		return new NetworkClient($this->clientService, $this->config);
	}
}
