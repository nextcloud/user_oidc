<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\AuditLog\Operation;
use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\Session;
use OCA\UserOIDC\Db\SessionMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\JweService;
use OCA\UserOIDC\Service\JwkService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Service\SettingsService;
use OCA\UserOIDC\Service\TokenService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\Vendor\Firebase\JWT\Key;
use OCA\UserOIDC\Vendor\Firebase\JWT\SignatureInvalidException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoginControllerTest extends TestCase {
	private const PROVIDER_ID = 3;
	private const ISSUER = 'https://idp.example.com';
	private const SIGNING_KEY = 'a-test-signing-key-that-is-long-enough-for-hs256-0123456789';

	private IRequest&MockObject $request;
	private ProviderMapper&MockObject $providerMapper;
	private ProviderService&MockObject $providerService;
	private DiscoveryService&MockObject $discoveryService;
	private ISession&MockObject $session;
	private HttpClientHelper&MockObject $clientService;
	private IUserSession&MockObject $userSession;
	private ITimeFactory&MockObject $timeFactory;
	private IConfig&MockObject $config;
	private IProvider&MockObject $authTokenProvider;
	private SessionMapper&MockObject $sessionMapper;
	private ProvisioningService&MockObject $provisioningService;
	private ICrypto&MockObject $crypto;
	private AuditLogger&MockObject $auditLogger;
	private LoginController $controller;
	private int $now;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->session = $this->createMock(ISession::class);
		$this->clientService = $this->createMock(HttpClientHelper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->config = $this->createMock(IConfig::class);
		$this->authTokenProvider = $this->createMock(IProvider::class);
		$this->sessionMapper = $this->createMock(SessionMapper::class);
		$this->provisioningService = $this->createMock(ProvisioningService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// the token library checks the expiration against the real clock
		$this->now = time();
		$this->timeFactory->method('getTime')->willReturn($this->now);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->config->method('getSystemValue')->with('user_oidc', [])->willReturn([]);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('parseUserId')->willReturnArgument(0);

		$this->controller = new LoginController(
			$this->request,
			$this->providerMapper,
			$this->providerService,
			$this->discoveryService,
			$this->createMock(LdapService::class),
			$settingsService,
			$this->createMock(ISecureRandom::class),
			$this->session,
			$this->clientService,
			$this->createMock(IURLGenerator::class),
			$this->userSession,
			$this->createMock(IUserManager::class),
			$this->timeFactory,
			$this->createMock(IEventDispatcher::class),
			$this->config,
			$this->createMock(IAppConfig::class),
			$this->authTokenProvider,
			$this->sessionMapper,
			$this->provisioningService,
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->crypto,
			$this->createMock(TokenService::class),
			$this->createMock(OIDCService::class),
			$this->createMock(JwkService::class),
			$this->createMock(JweService::class),
			$this->auditLogger,
		);
	}

	private function getProvider(): Provider {
		$provider = new Provider();
		$provider->setId(self::PROVIDER_ID);
		$provider->setIdentifier('keycloak');
		$provider->setClientId('nextcloud');
		$provider->setClientSecret('encrypted-secret');
		$provider->setDiscoveryEndpoint(self::ISSUER . '/.well-known/openid-configuration');
		return $provider;
	}

	/**
	 * @param array<string, mixed> $values session values of the login flow, without the state suffix
	 */
	private function givenALoginFlowSession(array $values): void {
		$values += [
			'oidc.state' => 'the-state',
			'oidc.timestamp' => $this->now,
			'oidc.login.providerid' => (string)self::PROVIDER_ID,
			'oidc.nonce' => 'the-nonce',
		];
		$map = [];
		foreach ($values as $key => $value) {
			$map[] = [$key . '-the-state', $value];
		}
		$this->session->method('get')->willReturnMap($map);
	}

	/**
	 * Lets the code flow reach the ID token validation with a token carrying $claims
	 *
	 * @param array<string, mixed> $claims
	 */
	private function givenAnIdToken(array $claims, string $signingKey = self::SIGNING_KEY): void {
		$this->givenALoginFlowSession([]);
		$this->providerMapper->method('getProvider')->with(self::PROVIDER_ID)->willReturn($this->getProvider());
		$this->crypto->method('decrypt')->willReturn('secret');
		$this->discoveryService->method('obtainDiscovery')->willReturn([
			'issuer' => self::ISSUER,
			'token_endpoint' => self::ISSUER . '/token',
		]);
		$this->providerService->method('getSetting')->willReturnCallback(
			static fn (int $providerId, string $key, string $default = ''): string => $key === ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS ? '1' : $default
		);

		$idToken = JWT::encode($claims + [
			'iss' => self::ISSUER,
			'aud' => 'nextcloud',
			'sub' => 'the-sub',
			'exp' => $this->now + 300,
			'nonce' => 'the-nonce',
		], $signingKey, 'HS256', 'key-1');
		$this->clientService->method('post')->willReturn(json_encode([
			'id_token' => $idToken,
			'access_token' => 'the-access-token',
		]));
		$this->discoveryService->method('obtainJWK')->willReturn(['key-1' => new Key(self::SIGNING_KEY, 'HS256')]);
	}

	private function expectLoginFailure(string $reason, ?int $providerId, ?string $userId = null): void {
		$this->auditLogger->expects($this->once())
			->method('log')
			->with(Operation::LOGIN_FAILED, 'OpenID Connect login failed: %s', [
				'reason' => $reason,
				'providerId' => $providerId,
				'userId' => $userId,
			]);
	}

	public function testCodeWithIdentityProviderError(): void {
		$this->givenALoginFlowSession([]);
		$this->expectLoginFailure('idp_error', self::PROVIDER_ID);

		$response = $this->controller->code('the-state', '', '', 'access_denied', 'The user denied the request');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCodeWithExpiredState(): void {
		$this->givenALoginFlowSession(['oidc.timestamp' => $this->now - 3600]);
		$this->expectLoginFailure('state_expired', self::PROVIDER_ID);

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCodeWithStateMismatch(): void {
		$this->givenALoginFlowSession(['oidc.state' => 'another-state']);
		$this->expectLoginFailure('state_mismatch', null);

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCodeWithFailingTokenRequest(): void {
		$this->givenALoginFlowSession([]);
		$this->providerMapper->method('getProvider')->willReturn($this->getProvider());
		$this->crypto->method('decrypt')->willReturn('secret');
		$this->discoveryService->method('obtainDiscovery')->willReturn(['issuer' => self::ISSUER, 'token_endpoint' => self::ISSUER . '/token']);
		$this->clientService->method('post')->willThrowException(new \RuntimeException('Connection refused'));
		$this->expectLoginFailure('token_request_failed', self::PROVIDER_ID);

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public static function dataCodeWithInvalidIdToken(): array {
		return [
			'issuer' => [['iss' => 'https://evil.example.com'], 'invalid_issuer'],
			'audience' => [['aud' => 'another-client'], 'invalid_audience'],
			'authorized party' => [['azp' => 'another-client'], 'invalid_azp'],
			'nonce' => [['nonce' => 'another-nonce'], 'invalid_nonce'],
		];
	}

	#[DataProvider('dataCodeWithInvalidIdToken')]
	public function testCodeWithInvalidIdToken(array $claims, string $reason): void {
		$this->givenAnIdToken($claims);
		$this->expectLoginFailure($reason, self::PROVIDER_ID);

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCodeWithForgedIdToken(): void {
		$this->givenAnIdToken([], 'another-signing-key-that-is-long-enough-for-hs256-0123456789');
		$this->expectLoginFailure('invalid_token', self::PROVIDER_ID);

		$this->expectException(SignatureInvalidException::class);
		$this->controller->code('the-state', 'the-code');
	}

	public function testCodeForUserOutsideOfTheWhitelistedGroups(): void {
		$this->givenAnIdToken([]);
		$this->provisioningService->method('getClaimValue')->willReturn('alice');
		$this->provisioningService->method('getSyncGroupsOfToken')->willReturn([]);
		$this->expectLoginFailure('not_in_whitelisted_group', self::PROVIDER_ID, 'alice');

		$response = $this->controller->code('the-state', 'the-code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCodeForLoggedInUserDoesNotLog(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->auditLogger->expects($this->never())->method('log');

		$this->controller->code('the-state', 'the-code');
	}

	private function givenABackChannelLogout(): void {
		$this->providerService->method('getProviderByIdentifier')->with('keycloak')->willReturn($this->getProvider());
		$this->discoveryService->method('obtainDiscovery')->willReturn(['issuer' => self::ISSUER]);
		$this->discoveryService->method('obtainJWK')->willReturn(['key-1' => new Key(self::SIGNING_KEY, 'HS256')]);

		$oidcSession = new Session();
		$oidcSession->setAuthtokenId(42);
		$this->sessionMapper->method('findSessionBySid')->with('the-sid', 'the-sub', self::ISSUER)->willReturn($oidcSession);
	}

	private function getLogoutToken(): string {
		return JWT::encode([
			'iss' => self::ISSUER,
			'aud' => 'nextcloud',
			'iat' => $this->now,
			'exp' => $this->now + 300,
			'jti' => 'the-jti',
			'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass()],
			'sid' => 'the-sid',
			'sub' => 'the-sub',
		], self::SIGNING_KEY, 'HS256', 'key-1');
	}

	public function testBackChannelLogoutRevokesSession(): void {
		$this->givenABackChannelLogout();
		$authToken = $this->createMock(IToken::class);
		$authToken->method('getId')->willReturn(42);
		$authToken->method('getUID')->willReturn('alice');
		$this->authTokenProvider->method('getTokenById')->with(42)->willReturn($authToken);
		$this->authTokenProvider->expects($this->once())->method('invalidateTokenById')->with('alice', 42);

		$this->auditLogger->expects($this->once())
			->method('log')
			->with(
				Operation::SESSION_REVOKED,
				'Session of user %s was revoked by a back-channel logout from OpenID Connect provider "%s"',
				['userId' => 'alice', 'identifier' => 'keycloak', 'providerId' => self::PROVIDER_ID, 'authTokenId' => 42],
			);

		$response = $this->controller->backChannelLogout('keycloak', $this->getLogoutToken());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBackChannelLogoutForUnknownAuthTokenDoesNotLog(): void {
		$this->givenABackChannelLogout();
		$this->authTokenProvider->method('getTokenById')->willThrowException(new InvalidTokenException());
		$this->authTokenProvider->expects($this->never())->method('invalidateTokenById');
		$this->auditLogger->expects($this->never())->method('log');

		$response = $this->controller->backChannelLogout('keycloak', $this->getLogoutToken());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBackChannelLogoutWithoutSessionDoesNotLog(): void {
		$this->providerService->method('getProviderByIdentifier')->willReturn($this->getProvider());
		$this->discoveryService->method('obtainDiscovery')->willReturn(['issuer' => self::ISSUER]);
		$this->discoveryService->method('obtainJWK')->willReturn(['key-1' => new Key(self::SIGNING_KEY, 'HS256')]);
		$this->sessionMapper->method('findSessionBySid')->willThrowException(new DoesNotExistException(''));
		$this->auditLogger->expects($this->never())->method('log');

		$response = $this->controller->backChannelLogout('keycloak', $this->getLogoutToken());
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
