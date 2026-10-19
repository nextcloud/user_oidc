<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Controller\Id4meController;
use OCA\UserOIDC\Db\Id4Me;
use OCA\UserOIDC\Db\Id4MeMapper;
use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\Vendor\Firebase\JWT\Key;
use OCA\UserOIDC\Vendor\Id4me\RP\Model\OpenIdConfig;
use OCA\UserOIDC\Vendor\Id4me\RP\Service;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class Id4meControllerTest extends TestCase {
	private const SIGNING_KEY = 'a-test-signing-key-that-is-long-enough-for-hs256-0123456789';

	private ISession&MockObject $session;
	private IConfig&MockObject $config;
	private ITimeFactory&MockObject $timeFactory;
	private IURLGenerator&MockObject $urlGenerator;
	private UserMapper&MockObject $userMapper;
	private IUserManager&MockObject $userManager;
	private HttpClientHelper&MockObject $clientHelper;
	private Id4MeMapper&MockObject $id4MeMapper;
	private ID4MeService&MockObject $id4MeService;
	private ICrypto&MockObject $crypto;
	private AuditLogger&MockObject $auditLogger;
	private Id4meController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->session = $this->createMock(ISession::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userMapper = $this->createMock(UserMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->clientHelper = $this->createMock(HttpClientHelper::class);
		$this->id4MeMapper = $this->createMock(Id4MeMapper::class);
		$this->id4MeService = $this->createMock(ID4MeService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new Id4meController(
			$this->createMock(IRequest::class),
			$this->createMock(ISecureRandom::class),
			$this->session,
			$this->config,
			$l10n,
			$this->timeFactory,
			$this->urlGenerator,
			$this->userMapper,
			$this->createMock(IUserSession::class),
			$this->userManager,
			$this->clientHelper,
			$this->id4MeMapper,
			$this->id4MeService,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->auditLogger,
		);

		$openIdConfig = $this->createMock(OpenIdConfig::class);
		$openIdConfig->method('getTokenEndpoint')->willReturn('https://id4me.example.com/token');
		$openIdConfig->method('getJwksUri')->willReturn('https://id4me.example.com/jwks');
		$id4meRpService = $this->createMock(Service::class);
		$id4meRpService->method('getOpenIdConfig')->with('example.com')->willReturn($openIdConfig);
		$property = new ReflectionProperty(Id4meController::class, 'id4me');
		$property->setValue($this->controller, $id4meRpService);
	}

	/**
	 * Lets the login reach the account lookup with an ID token carrying $nonce
	 */
	private function givenAnId4meLogin(string $nonce): void {
		$this->id4MeService->method('getID4ME')->willReturn(true);
		$this->session->method('get')->willReturnMap([
			['oidc.state', 'the-state'],
			['oidc.authname', 'example.com'],
			['oidc.nonce', 'the-nonce'],
		]);

		$id4Me = new Id4Me();
		$id4Me->setId(7);
		$id4Me->setIdentifier('example.com');
		$id4Me->setClientId('nextcloud');
		$id4Me->setClientSecret('encrypted-secret');
		$this->id4MeMapper->method('findByIdentifier')->with('example.com')->willReturn($id4Me);
		$this->crypto->method('decrypt')->with('encrypted-secret')->willReturn('secret');

		// the token library checks the expiration against the real clock
		$now = time();
		$idToken = JWT::encode([
			'iss' => 'https://id4me.example.com',
			'sub' => 'the-sub',
			'aud' => 'nextcloud',
			'exp' => $now + 300,
			'nonce' => $nonce,
		], self::SIGNING_KEY, 'HS256', 'key-1');
		$this->clientHelper->method('post')->willReturn(json_encode(['id_token' => $idToken]));
		$this->id4MeService->method('obtainJWK')->willReturn(['key-1' => new Key(self::SIGNING_KEY, 'HS256')]);
		$this->timeFactory->method('getTime')->willReturn($now);
		$this->config->method('getSystemValueString')->with('version', '0.0.0')->willReturn('33.0.0');
		$this->urlGenerator->method('linkToDefaultPageUrl')->willReturn('https://cloud.example.com/');
	}

	public static function dataCode(): array {
		return [
			'new account' => [true],
			'existing account' => [false],
		];
	}

	#[DataProvider('dataCode')]
	public function testCode(bool $created): void {
		$this->givenAnId4meLogin('the-nonce');

		$backendUser = new User();
		$backendUser->setUserId('backend-uid');
		$this->userMapper->expects($this->once())
			->method('getOrCreate')
			->with(7, 'the-sub', true)
			->willReturnCallback(static function (int $providerId, string $sub, bool $id4me = false, ?bool &$wasCreated = null) use ($backendUser, $created): User {
				$wasCreated = $created;
				return $backendUser;
			});
		$this->userManager->method('get')->with('backend-uid')->willReturn($this->createMock(IUser::class));

		$this->auditLogger->expects($created ? $this->once() : $this->never())
			->method('userProvisioned')
			->with('backend-uid', 7, 'id4me_login');

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	public function testCodeWithInvalidNonceDoesNotProvision(): void {
		$this->givenAnId4meLogin('another-nonce');

		$this->userMapper->expects($this->never())->method('getOrCreate');
		$this->auditLogger->expects($this->never())->method('userProvisioned');

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
