<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RedirectMiddleware;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\IConfig;
use OCP\IUserManager;

/**
 * @group DB
 */
class Test extends \Test\TestCase {
	private $oidcIdp = 'http://127.0.0.1:8999';
	private $baseUrl = 'http://localhost:8080';

	private $client;
	private $providerService;

	public function setUp(): void {
		parent::setUp();

		\OC::$server->getAppManager()->enableApp('user_oidc');

		if (getenv('IDP_URL')) {
			$this->oidcIdp = getenv('IDP_URL');
		}

		if (getenv('BASE_URL')) {
			$this->baseUrl = getenv('BASE_URL');
		}

		$this->newClient();
		$this->providerService = \OC::$server->get(ProviderService::class);
		$this->providerService->setSetting(1, ProviderService::SETTING_UNIQUE_UID, '1');
		$this->providerService->setSetting(1, ProviderService::SETTING_MAPPING_UID, '');
	}

	public function tearDown(): void {
		$this->cleanupUser('keycloak1');
		$this->cleanupUser('f617ef761d09b2afe1647c0bf9080ab75ec0d0fa1fbc95850091614f5fec1eea');
		$this->cleanupUser('9aa987a707a5d4699efb0753d0e1a9bb9303bf028d613538883fba0368c164da');
		$this->cleanupUser('aea81860-b25c-4f75-b9b5-9d632c3ba06f');
	}

	private function cleanupUser(string $userId): void {
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		if ($userManager->userExists($userId)) {
			$user = $userManager->get($userId);
			$user->delete();
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
		$response = $this->client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		self::assertStringStartsWith($this->oidcIdp, $headersRedirect[0]);
	}

	private function newClient() {
		$cookieJar = new CookieJar();
		$this->client = new Client(['allow_redirects' => ['track_redirects' => true], 'cookies' => $cookieJar]);
		return $this->client;
	}

	public function testLoginRedirectCallback() {
		$response = $this->client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		self::assertStringStartsWith($this->oidcIdp, $headersRedirect[0]);

		$response = $this->loginToKeycloak($headersRedirect[0], 'keycloak1', 'keycloak1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		$userId = $this->getUserHtmlData($response)['userId'];
		self::assertStringStartsWith($this->baseUrl . '/index.php/apps/user_oidc/code?', $headersRedirect[0]);
		self::assertEquals($this->baseUrl . '/index.php/apps/dashboard/', $headersRedirect[1]);

		// Validate login with correct user data
		$userInfo = $this->client->get($this->baseUrl . '/ocs/v1.php/cloud/users/' . $userId . '?format=json', ['auth' => ['admin', 'admin'], 'headers' => ['OCS-APIRequest' => 'true'],]);
		$userInfo = json_decode($userInfo->getBody()->getContents());
		self::assertEquals('keycloak1@example.com', $userInfo->ocs->data->email);
		self::assertEquals('Key Cloak 1', $userInfo->ocs->data->displayname);
		self::assertEquals(1073741824, $userInfo->ocs->data->quota->quota); // 1G

		$providerId = '1';
		$userIdHashed = hash('sha256', $providerId . '_0_' . 'aea81860-b25c-4f75-b9b5-9d632c3ba06f');
		self::assertEquals($userId, $userIdHashed);
	}

	private function loginToKeycloak($keycloakURL, $username, $password) {
		$response = $this->client->get($keycloakURL);
		$doc = new DOMDocument();
		$doc->loadHtml($response->getBody()->getContents());
		$selector = new DOMXpath($doc);
		$result = $selector->query('//form');
		$form = $result->item(0);
		$url = $form->getAttribute('action');
		libxml_clear_errors();
		return $this->client->post($url, ['form_params' => ['username' => $username, 'password' => $password, 'credentialId' => '']]);
	}

	private function getUserHtmlData($response) {
		$content = $response->getBody()->getContents();
		$doc = new DOMDocument();
		$doc->loadHtml($content);
		$body = $doc->getElementsByTagName('head')->item(0);
		$userId = $body->getAttribute('data-user');
		$userDisplayName = $body->getAttribute('data-user-displayname');
		libxml_clear_errors();
		return [
			'userId' => $userId,
			'displayName' => $userDisplayName,
		];
	}

	public function testDisabledAutoProvision() {
		sleep(5);
		/** @var IUserManager $userManager */
		$userManager = \OC::$server->get(IUserManager::class);
		if (!$userManager->userExists('keycloak1')) {
			$localUser = $userManager->createUser('keycloak1', 'passwordKeycloak1Local');
		} else {
			$localUser = $userManager->get('keycloak1');
		}
		self::assertNotEquals(false, $localUser);
		$localUser->setEMailAddress('keycloak1@local.com');
		$localUser->setDisplayName('Local name');

		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$config->setSystemValue('user_oidc', [ 'auto_provision' => false ]);

		$this->providerService->setSetting(1, ProviderService::SETTING_UNIQUE_UID, '0');
		$this->providerService->setSetting(1, ProviderService::SETTING_MAPPING_UID, 'preferred_username');

		$response = $this->client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		$response = $this->loginToKeycloak($headersRedirect[0], 'keycloak1', 'keycloak1');
		$status = $response->getStatusCode();
		self::assertEquals($status, 200);
		$userHtmlData = $this->getUserHtmlData($response);
		$userId = $userHtmlData['userId'];
		self::assertEquals($userId, 'keycloak1');
		$userDisplayName = $userHtmlData['displayName'];
		self::assertEquals($userDisplayName, 'Local name');

		// check the local user's attributes were not replaced by the keycloak ones
		$userInfo = $this->client->get($this->baseUrl . '/ocs/v1.php/cloud/users/' . $userId . '?format=json', ['auth' => ['admin', 'admin'], 'headers' => ['OCS-APIRequest' => 'true'],]);
		$userInfo = json_decode($userInfo->getBody()->getContents());
		self::assertEquals('keycloak1@local.com', $userInfo->ocs->data->email);
		self::assertEquals('Local name', $userInfo->ocs->data->displayname);

		// restore initial settings
		$localUser->delete();
		$config->setSystemValue('user_oidc', [ 'auto_provision' => true ]);
		$this->providerService->setSetting(1, ProviderService::SETTING_UNIQUE_UID, '1');
		$this->providerService->setSetting(1, ProviderService::SETTING_MAPPING_UID, '');
		sleep(5);
	}

	public function testUnreachable() {
		$provider = $this->providerService->getProviderByIdentifier('nextcloudci');
		/** @var ProviderMapper $mapper */
		$mapper = \OC::$server->get(ProviderMapper::class);

		$previousDiscovery = $provider->getDiscoveryEndpoint();

		$provider->setDiscoveryEndpoint('http://unreachable/url');
		$mapper->update($provider);

		try {
			$client = new Client(['allow_redirects' => ['track_redirects' => true]]);
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
		$this->providerService->setSetting(1, ProviderService::SETTING_UNIQUE_UID, '0');

		$response = $this->client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		$response = $this->loginToKeycloak($headersRedirect[0], 'keycloak1', 'keycloak1');
		$userId = $this->getUserHtmlData($response)['userId'];
		self::assertEquals($userId, 'aea81860-b25c-4f75-b9b5-9d632c3ba06f');
	}

	public function testNonUniqueMapping() {
		$this->providerService->setSetting(1, ProviderService::SETTING_UNIQUE_UID, '0');
		$this->providerService->setSetting(1, ProviderService::SETTING_MAPPING_UID, 'preferred_username');

		$response = $this->client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		$response = $this->loginToKeycloak($headersRedirect[0], 'keycloak1', 'keycloak1');
		$userId = $this->getUserHtmlData($response)['userId'];
		self::assertEquals($userId, 'keycloak1');
	}

	public function testUniqueMapping() {
		$this->providerService->setSetting(1, ProviderService::SETTING_MAPPING_UID, 'preferred_username');

		$response = $this->client->get($this->baseUrl . '/index.php/apps/user_oidc/login/1');
		$headersRedirect = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
		$response = $this->loginToKeycloak($headersRedirect[0], 'keycloak1', 'keycloak1');
		$userId = $this->getUserHtmlData($response)['userId'];
		self::assertEquals($userId, hash('sha256', '1_0_keycloak1'));
	}
}
