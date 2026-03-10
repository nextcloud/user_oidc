<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Tests\Unit\Controller;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OC\Authentication\Token\IProvider;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\SessionMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\OIDCService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\Service\SettingsService;
use OCA\UserOIDC\Service\TokenService;
use OCA\UserOIDC\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
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
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase {

	private const TEST_SECRET = 'phpunit-hs256-test-secret-32bytes!';
	private const TEST_KID = 'test-kid';
	private const VALID_STATE = 'VALIDSTATE1234567890123456789012';
	private const VALID_CLIENT_ID = 'test-client-id';
	private const VALID_ISSUER = 'https://idp.example.com';
	private const VALID_NONCE = 'TESTNONCE1234567890123456789012';
	private const VALID_USER_ID = 'john.doe';

	// Mutable state for mock callbacks
	private array $oidcSystemConfig = [];
	private bool $debugMode = false;
	private array $providerSettings = [];
	private ?string $provisionedUserId = self::VALID_USER_ID;
	private array $provisionedUserData = ['user' => null, 'userData' => []];
	private ?IUser $existingUserMock = null;

	private MockObject&IRequest $request;
	private MockObject&ProviderMapper $providerMapper;
	private MockObject&ProviderService $providerService;
	private MockObject&DiscoveryService $discoveryService;
	private MockObject&LdapService $ldapService;
	private MockObject&SettingsService $settingsService;
	private MockObject&ISecureRandom $random;
	private MockObject&ISession $session;
	private MockObject&HttpClientHelper $clientService;
	private MockObject&IURLGenerator $urlGenerator;
	private MockObject&IUserSession $userSession;
	private MockObject&IUserManager $userManager;
	private MockObject&ITimeFactory $timeFactory;
	private MockObject&IEventDispatcher $eventDispatcher;
	private MockObject&IConfig $config;
	private MockObject&IAppConfig $appConfig;
	private MockObject&IProvider $authTokenProvider;
	private MockObject&SessionMapper $sessionMapper;
	private MockObject&ProvisioningService $provisioningService;
	private MockObject&IL10N $l10n;
	private MockObject&LoggerInterface $logger;
	private MockObject&ICrypto $crypto;
	private MockObject&TokenService $tokenService;
	private MockObject&OIDCService $oidcService;

	private LoginController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->ldapService = $this->createMock(LdapService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->session = $this->createMock(ISession::class);
		$this->clientService = $this->createMock(HttpClientHelper::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->authTokenProvider = $this->createMock(IProvider::class);
		$this->sessionMapper = $this->createMock(SessionMapper::class);
		$this->provisioningService = $this->createMock(ProvisioningService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->tokenService = $this->createMock(TokenService::class);
		$this->oidcService = $this->createMock(OIDCService::class);

		$this->l10n->method('t')->willReturnArgument(0);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->appConfig->method('getValueString')->willReturn('0');

		$this->config
			->method('getSystemValue')
			->willReturnCallback(
				fn (string $key, mixed $default = null) => match ($key) {
					'debug' => $this->debugMode,
					'user_oidc' => $this->oidcSystemConfig,
					default => $default,
				}
			);

		$this->providerService
			->method('getSetting')
			->willReturnCallback(
				fn (int $id, string $setting, string $default = '')
					=> $this->providerSettings[$setting] ?? $default
			);

		$this->provisioningService
			->method('getClaimValue')
			->willReturnCallback(fn () => $this->provisionedUserId);

		$this->provisioningService
			->method('provisionUser')
			->willReturnCallback(fn () => $this->provisionedUserData);

		$this->userManager
			->method('get')
			->willReturnCallback(fn () => $this->existingUserMock);

		$this->controller = new LoginController(
			$this->request,
			$this->providerMapper,
			$this->providerService,
			$this->discoveryService,
			$this->ldapService,
			$this->settingsService,
			$this->random,
			$this->session,
			$this->clientService,
			$this->urlGenerator,
			$this->userSession,
			$this->userManager,
			$this->timeFactory,
			$this->eventDispatcher,
			$this->config,
			$this->appConfig,
			$this->authTokenProvider,
			$this->sessionMapper,
			$this->provisioningService,
			$this->l10n,
			$this->logger,
			$this->crypto,
			$this->tokenService,
			$this->oidcService,
		);
	}

	private function setSystemConfig(array $oidcConfig, bool $debug = false): void {
		$this->oidcSystemConfig = $oidcConfig;
		$this->debugMode = $debug;
	}

	private function setupSession(array $overrides = []): void {
		$data = array_merge([
			'oidc.state' => self::VALID_STATE,
			'oidc.providerid' => '1',
			'oidc.nonce' => self::VALID_NONCE,
			'oidc.redirect' => '/apps/dashboard',
		], $overrides);

		$this->session
			->method('get')
			->willReturnCallback(fn (string $key) => $data[$key] ?? null);

		$this->session->method('getId')->willReturn('nc-session-id');
	}

	private function makeProvider(): Provider {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setClientId(self::VALID_CLIENT_ID);
		$provider->setClientSecret('encrypted-secret');
		return $provider;
	}

	private function setupUpToTokenEndpoint(): void {
		$this->setupSession();
		$this->providerMapper->method('getProvider')->willReturn($this->makeProvider());
		$this->crypto->method('decrypt')->willReturn('plain-secret');
		$this->discoveryService->method('obtainDiscovery')->willReturn([
			'token_endpoint' => 'https://idp.example.com/token',
			'issuer' => self::VALID_ISSUER,
		]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturn('https://nc.example.com/callback');
	}

	private function buildJwt(array $claims): string {
		$header = ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => self::TEST_KID];

		$h = $this->base64UrlEncode((string)json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$p = $this->base64UrlEncode((string)json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$sig = hash_hmac('sha256', "$h.$p", self::TEST_SECRET, true);

		return "$h.$p." . $this->base64UrlEncode($sig);
	}

	private static function base64UrlEncode(string $input): string {
		return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
	}

	private function buildJwks(): array {
		return [self::TEST_KID => new Key(self::TEST_SECRET, 'HS256')];
	}

	private function validClaims(array $overrides = []): array {
		$base = [
			'iss' => self::VALID_ISSUER,
			'aud' => self::VALID_CLIENT_ID,
			'sub' => self::VALID_USER_ID,
			'exp' => time() + 3600,
			'iat' => time(),
			'nonce' => self::VALID_NONCE,
		];

		foreach ($overrides as $key => $value) {
			if ($value === null) {
				unset($base[$key]);
			} else {
				$base[$key] = $value;
			}
		}

		return $base;
	}

	private function setupUpToJwtValidation(array $claimOverrides = []): string {
		$this->setupUpToTokenEndpoint();

		$jwt = $this->buildJwt($this->validClaims($claimOverrides));

		$this->clientService->method('post')->willReturn(json_encode([
			'access_token' => 'test-access-token',
			'token_type' => 'Bearer',
			'id_token' => $jwt,
		], JSON_THROW_ON_ERROR));

		$this->discoveryService->method('obtainJWK')->willReturn($this->buildJwks());

		return $jwt;
	}

	private function setupUpToProvisioning(array $claimOverrides = [], array $oidcConfig = []): void {
		$this->setupUpToJwtValidation($claimOverrides);
		$this->setSystemConfig($oidcConfig);

		$this->providerSettings = [
			ProviderService::SETTING_MAPPING_UID => 'sub',
			ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS => '0',
		];

		$this->ldapService->method('isLDAPEnabled')->willReturn(false);
	}

	private function makeClientException(?string $body = null): ClientException {
		$req = new GuzzleRequest('POST', 'https://idp.example.com/token');
		$res = new GuzzleResponse(400, [], $body ?? '');
		return new ClientException('Client error', $req, $res);
	}

	private function makeServerException(): ServerException {
		$req = new GuzzleRequest('POST', 'https://idp.example.com/token');
		$res = new GuzzleResponse(500, [], 'Internal Server Error');
		return new ServerException('Server error', $req, $res);
	}

	private function setupSuccessfulLogin(?MockObject $existingUser = null): MockObject&IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::VALID_USER_ID);
		$user->method('canChangeAvatar')->willReturn(false);

		$this->existingUserMock = $existingUser;

		$this->provisionedUserData = [
			'user' => $user,
			'userData' => [],
		];

		$this->authTokenProvider
			->method('getToken')
			->willThrowException(new InvalidTokenException('not found'));

		return $user;
	}

	private function captureDispatchedEvents(array &$dispatchedEvents): void {
		$this->eventDispatcher
			->method('dispatchTyped')
			->willReturnCallback(function (object $event) use (&$dispatchedEvents): void {
				$dispatchedEvents[] = $event;
			});
	}

	#[Test]
	#[Group('security')]
	public function codeReturnsProtocolErrorWhenConnectionIsNotHttps(): void {
		$this->request->method('getServerProtocol')->willReturn('http');
		$response = $this->controller->code(state: self::VALID_STATE, code: 'any');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('error-param')]
	public function codeReturns400WhenIdpReturnsError(): void {
		$response = $this->controller->code(
			state: self::VALID_STATE,
			error: 'access_denied',
			error_description: 'User cancelled',
		);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('error-param')]
	public function codeReturnsTemplateResponseWhenIdpReturnsErrorWithDebugEnabled(): void {
		$this->setSystemConfig([], debug: true);
		$response = $this->controller->code(
			state: self::VALID_STATE,
			error: 'access_denied',
			error_description: 'User cancelled',
		);
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('state')]
	public function codeReturns403WhenStateMismatch(): void {
		$this->session->method('get')->willReturn('TOTALLY_DIFFERENT_STATE');
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('state')]
	public function codeReturns403WhenSessionStateIsNull(): void {
		$this->session->method('get')->willReturn(null);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('state')]
	public function codeReturnsTemplateResponseWhenStateMismatchAndDebugEnabled(): void {
		$this->setSystemConfig([], debug: true);
		$this->session->method('get')->willReturn('WRONG_STATE');
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('crypto')]
	public function codeReturns400WhenClientSecretDecryptionFails(): void {
		$this->setupUpToTokenEndpoint();
		$this->crypto->method('decrypt')
			->willThrowException(new \Exception('Key mismatch'));
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('token-endpoint')]
	public function codeReturns403WhenClientExceptionHasStructuredError(): void {
		$this->setupUpToTokenEndpoint();
		$this->clientService->method('post')->willThrowException(
			$this->makeClientException(
				json_encode(['error' => 'invalid_grant', 'error_description' => 'Code expired'])
			)
		);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('token-endpoint')]
	public function codeReturns403WhenClientExceptionHasNoStructuredError(): void {
		$this->setupUpToTokenEndpoint();
		$this->clientService->method('post')
			->willThrowException($this->makeClientException('Bad Request'));
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('token-endpoint')]
	public function codeReturns403WhenServerExceptionOccurs(): void {
		$this->setupUpToTokenEndpoint();
		$this->clientService->method('post')
			->willThrowException($this->makeServerException());
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('token-endpoint')]
	public function codeReturns403WhenGenericExceptionOccursAtTokenEndpoint(): void {
		$this->setupUpToTokenEndpoint();
		$this->clientService->method('post')
			->willThrowException(new \RuntimeException('Network timeout'));
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public static function invalidTokenResponseBodies(): array {
		return [
			'empty body' => [''],
			'invalid JSON' => ['not-json{{{{'],
			'JSON null' => ['null'],
			'JSON string' => ['"just-a-string"'],
			'missing id_token' => [json_encode(['access_token' => 'tok', 'token_type' => 'Bearer'])],
			'id_token is null' => [json_encode(['access_token' => 'tok', 'id_token' => null])],
			'id_token is integer' => [json_encode(['access_token' => 'tok', 'id_token' => 42])],
		];
	}

	#[Test]
	#[Group('token-parsing')]
	#[DataProvider('invalidTokenResponseBodies')]
	public function codeReturns403WhenTokenEndpointResponseIsInvalid(string $body): void {
		$this->setupUpToTokenEndpoint();
		$this->clientService->method('post')->willReturn($body);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('jwt')]
	public function codeReturns403WhenTokenIsExpired(): void {
		$this->setupUpToJwtValidation(['exp' => time() + 3600]);
		$this->timeFactory->method('getTime')->willReturn(time() + 7200);

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('jwt')]
	public function codeReturns403WhenIssuerDoesNotMatchDiscovery(): void {
		$this->setupUpToJwtValidation(['iss' => 'https://evil-idp.example.com']);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public static function invalidAudiences(): array {
		return [
			'wrong audience as string' => ['wrong-client-id'],
			'wrong audience in array' => [['other-client', 'another-client']],
			'empty audience array' => [[]],
		];
	}

	#[Test]
	#[Group('jwt')]
	#[DataProvider('invalidAudiences')]
	public function codeReturns403WhenAudienceIsInvalid(string|array $aud): void {
		$this->setupUpToJwtValidation(['aud' => $aud]);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('jwt')]
	public function codePassesAudienceValidationWhenClientIdIsInAudienceArray(): void {
		$this->setupUpToProvisioning(['aud' => [self::VALID_CLIENT_ID, 'other-client']]);
		$this->setupSuccessfulLogin();
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	#[Test]
	#[Group('jwt')]
	public function codeReturns403WhenAuthorizedPartyMismatch(): void {
		$this->setupUpToJwtValidation(['azp' => 'wrong-client-id']);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('jwt')]
	public function codeSkipsAzpValidationWhenDisabledInConfig(): void {
		$this->setupUpToProvisioning(
			claimOverrides: ['azp' => 'wrong-client-id'],
			oidcConfig: ['login_validation_azp_check' => false],
		);
		$this->setupSuccessfulLogin();
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	#[Test]
	#[Group('jwt')]
	public function codeReturns403WhenNonceDoesNotMatchSession(): void {
		$this->setupUpToJwtValidation(['nonce' => 'COMPLETELY_DIFFERENT_NONCE']);
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('jwt')]
	public function codeAcceptsTokenWithNoNonceClaim(): void {
		$this->setupUpToProvisioning(claimOverrides: ['nonce' => null]);
		$this->setupSuccessfulLogin();
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	#[Test]
	#[Group('provisioning')]
	public function codeReturns400WhenUserIdClaimIsMissingFromToken(): void {
		$this->setupUpToProvisioning();
		$this->provisionedUserId = null;
		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('provisioning')]
	public function codeReturns403WhenUserIsNotInWhitelistedGroup(): void {
		$this->setupUpToProvisioning();
		$this->providerSettings[ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS] = '1';
		$this->provisioningService->method('getSyncGroupsOfToken')->willReturn([]);

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('provisioning')]
	public function codeAllowsLoginWhenUserBelongsToWhitelistedGroup(): void {
		$this->setupUpToProvisioning();
		$this->providerSettings[ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS] = '1';
		$this->provisioningService->method('getSyncGroupsOfToken')->willReturn(['admins']);
		$this->setupSuccessfulLogin();

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	#[Test]
	#[Group('provisioning')]
	public function codeReturns403WhenAccountCreationIsDisabledForNewUser(): void {
		$this->setupUpToProvisioning(oidcConfig: ['disable_account_creation' => true]);
		$this->existingUserMock = null;

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	#[Test]
	#[Group('provisioning')]
	public function codeAllowsLoginWhenAccountCreationIsDisabledButUserAlreadyExists(): void {
		$this->setupUpToProvisioning(oidcConfig: ['disable_account_creation' => true]);
		$existingUser = $this->createMock(IUser::class);
		$existingUser->method('getBackendClassName')->willReturn(Application::APP_ID);
		$this->setupSuccessfulLogin(existingUser: $existingUser);

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	#[Test]
	#[Group('provisioning')]
	public function codeReturns400WhenUserExistsInAnotherBackendWithoutSoftProvision(): void {
		$this->setupUpToProvisioning(oidcConfig: ['soft_auto_provision' => false]);
		$existingUser = $this->createMock(IUser::class);
		$existingUser->method('getBackendClassName')->willReturn('OCA\User_LDAP\User_LDAP');
		$this->existingUserMock = $existingUser;

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('provisioning')]
	public function codeReturns400WhenProvisionUserReturnsNull(): void {
		$this->setupUpToProvisioning();
		$this->existingUserMock = null;

		$this->provisionedUserData = [
			'user' => null,
			'userData' => [],
		];

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('provisioning')]
	public function codeReturns400WhenAutoProvisionIsDisabledAndUserDoesNotExist(): void {
		$this->setupUpToProvisioning(oidcConfig: ['auto_provision' => false]);
		$this->existingUserMock = null;

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	#[Test]
	#[Group('provisioning')]
	public function codeAllowsLoginWhenAutoProvisionIsDisabledAndUserExists(): void {
		$this->setupUpToProvisioning(oidcConfig: ['auto_provision' => false]);
		$existingUser = $this->createMock(IUser::class);
		$existingUser->method('getUID')->willReturn(self::VALID_USER_ID);
		$existingUser->method('canChangeAvatar')->willReturn(false);

		$this->existingUserMock = $existingUser;

		$this->authTokenProvider
			->method('getToken')
			->willThrowException(new InvalidTokenException('not found'));

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');
		$this->assertInstanceOf(RedirectResponse::class, $response);
	}

	#[Test]
	#[Group('login')]
	public function codeDispatchesUserCreatedEventWhenNewUserIsProvisioned(): void {
		$this->setupUpToProvisioning();
		$this->existingUserMock = null;
		$this->setupSuccessfulLogin(existingUser: null);

		$dispatchedEvents = [];
		$this->captureDispatchedEvents($dispatchedEvents);

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$userCreatedEvents = array_filter(
			$dispatchedEvents,
			fn (object $e) => $e instanceof UserCreatedEvent
		);
		$this->assertCount(1, $userCreatedEvents, 'UserCreatedEvent must be dispatched exactly once for a new user');
	}

	#[Test]
	#[Group('login')]
	public function codeDoesNotDispatchUserCreatedEventWhenUserAlreadyExisted(): void {
		$this->setupUpToProvisioning();
		$existingUser = $this->createMock(IUser::class);
		$existingUser->method('getBackendClassName')->willReturn(Application::APP_ID);
		$this->setupSuccessfulLogin(existingUser: $existingUser);

		$dispatchedEvents = [];
		$this->captureDispatchedEvents($dispatchedEvents);

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$userCreatedEvents = array_filter(
			$dispatchedEvents,
			fn (object $e) => $e instanceof UserCreatedEvent
		);
		$this->assertCount(0, $userCreatedEvents, 'UserCreatedEvent must not be dispatched for an existing user');
	}

	#[Test]
	#[Group('login')]
	public function codeRedirectsToSessionUrlAfterSuccessfulLogin(): void {
		$this->setupUpToProvisioning();
		$this->setupSuccessfulLogin();

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/dashboard', $response->getRedirectURL());
	}

	#[Test]
	#[Group('login')]
	public function codeRedirectsToBaseUrlWhenSessionRedirectUrlIsAbsoluteExternal(): void {
		$this->setupSession(['oidc.redirect' => 'https://evil.example.com/steal']);
		$this->setupUpToJwtValidation();
		$this->setSystemConfig([]);
		$this->providerSettings = [
			ProviderService::SETTING_MAPPING_UID => 'sub',
			ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS => '0',
		];
		$this->ldapService->method('isLDAPEnabled')->willReturn(false);
		$this->setupSuccessfulLogin();
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://nc.example.com');

		$response = $this->controller->code(state: self::VALID_STATE, code: 'code');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertStringNotContainsString('evil.example.com', $response->getRedirectURL());
	}

	#[Test]
	#[Group('login')]
	public function codeSetsHadTokenOnceUserConfigAfterLogin(): void {
		$this->setupUpToProvisioning();
		$this->setupSuccessfulLogin();

		$this->config->expects($this->once())
			->method('setUserValue')
			->with(self::VALID_USER_ID, Application::APP_ID, 'had_token_once', '1');

		$this->controller->code(state: self::VALID_STATE, code: 'code');
	}
}
