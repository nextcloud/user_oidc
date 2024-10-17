<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Helper;

use OCP\Http\Client\IClientService;

require_once __DIR__ . '/../../vendor/autoload.php';
use Id4me\RP\HttpClient;

class HttpClientHelper implements HttpClient {

	public function __construct(
		private IClientService $clientService,
	) {
	}

	public function get($url, array $headers = []) {
		$client = $this->clientService->newClient();

		return $client->get($url, [
			'headers' => $headers,
		])->getBody();
	}

	public function post($url, $body, array $headers = []) {
		$client = $this->clientService->newClient();

		return $client->post($url, [
			'headers' => $headers,
			'body' => $body,
		])->getBody();
	}
}
