<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

use GuzzleHttp\RedirectMiddleware;
use OCA\UserOIDC\Service\ProviderService;

/**
 * @group DB
 */
class Test extends \Test\TestCase {
	private $oidcIdp = 'http://127.0.0.1:8999';
	private $baseUrl = 'http://localhost:8080';

	public function setUp(): void {
		parent::setUp();

		\OC::$server->getAppManager()->enableApp('user_oidc');

		if (getenv('IDP_URL')) {
			$this->oidcIdp = getenv('IDP_URL');
		}

		if (getenv('BASE_URL')) {
			$this->baseUrl = getenv('BASE_URL');
		}
	}

	public function testAlternativeLogins() {
		self::assertEquals([
			[
				'name' => 'Login with nextcloudci',
				'href' => '/index.php/apps/user_oidc/login/1'
			]
		], OC_App::getAlternativeLogIns());
	}

	public function testLoginRedirect() {
		$client = new \GuzzleHttp\Client(['allow_redirects' => ['track_redirects' => true]]);
		$response = $client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		self::assertStringStartsWith($this->oidcIdp, $headersRedirect[0]);
	}

	public function testLoginRedirectCallback() {
		$cookieJar = new \GuzzleHttp\Cookie\CookieJar();
		$client = new \GuzzleHttp\Client(['allow_redirects' => ['track_redirects' => true], 'cookies' => $cookieJar]);
		$response = $client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		self::assertStringStartsWith($this->oidcIdp, $headersRedirect[0]);

		$params = [];
		parse_str(parse_url($headersRedirect[0])['query'], $params);

		// Login into keycloak
		$response = $client->get($headersRedirect[0]);
		$doc = new DOMDocument();
		$doc->loadHtml($response->getBody()->getContents());
		$selector = new DOMXpath($doc);
		$result = $selector->query('//form');
		$form = $result->item(0);
		$url = $form->getAttribute('action');
		libxml_clear_errors();

		$response = $client->post($url, ['form_params' => ['username' => 'keycloak1', 'password' => 'keycloak1', "credentialId" => '']]);
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);

		$content = $response->getBody()->getContents();
		$doc = new DOMDocument();
		$doc->loadHtml($content);
		$body = $doc->getElementsByTagName('head')->item(0);
		$userId = $body->getAttribute('data-user');
		libxml_clear_errors();
		self::assertStringStartsWith($this->baseUrl . '/index.php/apps/user_oidc/code?', $headersRedirect[0]);
		self::assertEquals($this->baseUrl . '/index.php/apps/dashboard/', $headersRedirect[1]);

		// Validate login with correct user data
		$userInfo = $client->get($this->baseUrl . '/ocs/v1.php/cloud/users/' . $userId . '?format=json', ['auth' => ['admin', 'admin'], 'headers' => ['OCS-APIRequest' => 'true'],]);
		$userInfo = json_decode($userInfo->getBody()->getContents());
		self::assertEquals('keycloak1@example.com', $userInfo->ocs->data->email);
		self::assertEquals('Key Cloak 1', $userInfo->ocs->data->displayname);

		$providerId = '1';
		$userIdHashed = hash('sha256', $providerId . '_0_' . 'aea81860-b25c-4f75-b9b5-9d632c3ba06f');
		self::assertEquals($userId, $userIdHashed);
	}

	public function testUnreachable() {
		/** @var ProviderService $service */
		$service = \OC::$server->get(ProviderService::class);
		$mapper = \OC::$server->get(\OCA\UserOIDC\Db\ProviderMapper::class);
		$provider = $service->getProviderByIdentifier('nextcloudci');

		$previousDiscovery = $provider->getDiscoveryEndpoint();

		$provider->setDiscoveryEndpoint('http://unreachable/url');
		$mapper->update($provider);

		try {
			$client = new \GuzzleHttp\Client(['allow_redirects' => ['track_redirects' => true]]);
			$response = $client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		} catch (\Exception $e) {
			$response = $e->getResponse();
		}
		$status = $response->getStatusCode();

		$provider->setDiscoveryEndpoint($previousDiscovery);
		$mapper->update($provider);

		self::assertEquals($status, 404);
	}

	public function testNonUnique() {
	}
}
