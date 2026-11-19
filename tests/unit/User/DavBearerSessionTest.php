<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Service\SettingsService;
use OCA\UserOIDC\User\Backend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * isSessionActive() is the gate in front of OC_User::loginWithApache().
 * A true result on a DAV Bearer request is what writes oc_authtoken.
 */
class DavBearerSessionTest extends TestCase {
	private IRequest&MockObject $request;
	private ISession&MockObject $session;
	private Backend $backend;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->backend = new Backend(
			$this->createMock(IConfig::class),
			$this->createMock(UserMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->request,
			$this->session,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(DiscoveryService::class),
			$this->createMock(ProviderMapper::class),
			$this->createMock(ProviderService::class),
			$this->createMock(ProvisioningService::class),
			$this->createMock(LdapService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(AuditLogger::class),
		);
	}

	#[DataProvider('statelessDavBearerProvider')]
	public function testDavOidcBearerIsNotAnApacheSession(string $requestUri): void {
		$this->givenAuthorization('Bearer access-token', $requestUri);

		$this->assertFalse($this->backend->isSessionActive());
	}

	#[DataProvider('statefulRequestProvider')]
	public function testOtherRequestsKeepTheApacheSession(string $authorization, string $requestUri): void {
		$this->givenAuthorization($authorization, $requestUri);

		$this->assertTrue($this->backend->isSessionActive());
	}

	public function testABrowserSessionOnDavStaysActiveWithoutBearer(): void {
		$this->givenAuthorization('', '/remote.php/dav/files/user/');
		$this->session->method('get')->with(LoginController::PROVIDERID)->willReturn(5);

		$this->assertTrue($this->backend->isSessionActive());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function statelessDavBearerProvider(): array {
		return [
			'files' => ['/remote.php/dav/files/user/'],
			'calendars' => ['/remote.php/dav/calendars/user/personal/'],
			'prefixed webroot' => ['/nextcloud/remote.php/dav/files/user/'],
		];
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function statefulRequestProvider(): array {
		return [
			'ocs bearer' => ['Bearer access-token', '/ocs/v2.php/cloud/user'],
			'legacy webdav' => ['Bearer access-token', '/remote.php/webdav/'],
			'legacy caldav' => ['Bearer access-token', '/remote.php/caldav/'],
			'basic on dav' => ['Basic dXNlcjpwYXNz', '/remote.php/dav/files/user/'],
		];
	}

	private function givenAuthorization(string $authorization, string $requestUri): void {
		$this->request->method('getHeader')->with(Application::OIDC_API_REQ_HEADER)->willReturn($authorization);
		$this->request->method('getRequestUri')->willReturn($requestUri);
		$this->request->method('getScriptName')->willReturn('');
		$this->request->method('getPathInfo')->willReturn('');
	}
}
