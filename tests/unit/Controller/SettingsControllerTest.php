<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Controller\SettingsController;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsControllerTest extends TestCase {
	private const DISCOVERY_ENDPOINT = 'https://idp.example.com/.well-known/openid-configuration';

	private IAppConfig&MockObject $appConfig;
	private ProviderMapper&MockObject $providerMapper;
	private ID4MeService&MockObject $id4meService;
	private ProviderService&MockObject $providerService;
	private ICrypto&MockObject $crypto;
	private HttpClientHelper&MockObject $httpClientHelper;
	private AuditLogger&MockObject $auditLogger;
	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->id4meService = $this->createMock(ID4MeService::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->httpClientHelper = $this->createMock(HttpClientHelper::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);

		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $secret): string => 'encrypted-' . $secret);

		$this->controller = new SettingsController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->providerMapper,
			$this->id4meService,
			$this->providerService,
			$this->crypto,
			$this->httpClientHelper,
			$this->createMock(LoggerInterface::class),
			$this->auditLogger,
		);
	}

	private function givenAValidDiscoveryEndpoint(): void {
		$this->httpClientHelper->method('get')->with(self::DISCOVERY_ENDPOINT)->willReturn(json_encode([
			'issuer' => 'https://idp.example.com',
			'authorization_endpoint' => 'https://idp.example.com/auth',
			'token_endpoint' => 'https://idp.example.com/token',
			'jwks_uri' => 'https://idp.example.com/jwks',
			'response_types_supported' => ['code'],
			'subject_types_supported' => ['public'],
			'id_token_signing_alg_values_supported' => ['RS256'],
		]));
	}

	private function getProvider(): Provider {
		$provider = new Provider();
		$provider->setId(3);
		$provider->setIdentifier('keycloak');
		$provider->setClientId('nextcloud');
		$provider->setClientSecret('encrypted-old-secret');
		$provider->setDiscoveryEndpoint(self::DISCOVERY_ENDPOINT);
		return $provider;
	}

	public function testCreateProvider(): void {
		$this->givenAValidDiscoveryEndpoint();
		$this->providerService->method('getProviderByIdentifier')->with('keycloak')->willReturn(null);
		$this->providerMapper->method('insert')->willReturnCallback(static function (Provider $provider): Provider {
			$provider->setId(3);
			return $provider;
		});
		$this->providerService->method('setSettings')->willReturn([]);

		$this->auditLogger->expects($this->once())
			->method('providerCreated')
			->with($this->callback(static fn (Provider $provider): bool => $provider->getId() === 3
				&& $provider->getIdentifier() === 'keycloak'
				&& $provider->getClientSecret() === 'encrypted-secret'));

		$response = $this->controller->createProvider('keycloak', 'nextcloud', 'secret', self::DISCOVERY_ENDPOINT);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCreateExistingProviderDoesNotLog(): void {
		$this->providerService->method('getProviderByIdentifier')->with('keycloak')->willReturn($this->getProvider());
		$this->providerMapper->expects($this->never())->method('insert');
		$this->auditLogger->expects($this->never())->method('providerCreated');

		$response = $this->controller->createProvider('keycloak', 'nextcloud', 'secret', self::DISCOVERY_ENDPOINT);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testCreateProviderWithInvalidDiscoveryEndpointDoesNotLog(): void {
		$this->providerService->method('getProviderByIdentifier')->willReturn(null);
		$this->httpClientHelper->method('get')->willReturn('');
		$this->auditLogger->expects($this->never())->method('providerCreated');

		$response = $this->controller->createProvider('keycloak', 'nextcloud', 'secret', self::DISCOVERY_ENDPOINT);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function dataUpdateProvider(): array {
		return [
			'new client secret' => ['new-secret', true],
			'client secret unchanged' => [null, false],
			'empty client secret' => ['', false],
		];
	}

	#[DataProvider('dataUpdateProvider')]
	public function testUpdateProvider(?string $clientSecret, bool $clientSecretChanged): void {
		$this->givenAValidDiscoveryEndpoint();
		$provider = $this->getProvider();
		$this->providerMapper->method('getProvider')->with(3)->willReturn($provider);
		$this->providerService->method('getProviderByIdentifier')->with('keycloak')->willReturn($provider);
		$this->providerMapper->method('update')->willReturnArgument(0);
		$settingsBefore = ['checkBearer' => false];
		$settingsAfter = ['checkBearer' => true];
		$this->providerService->method('getSettings')->with(3)->willReturnOnConsecutiveCalls($settingsBefore, $settingsAfter);
		$this->providerService->method('setSettings')->willReturn($settingsAfter);

		$this->auditLogger->expects($this->once())
			->method('providerUpdated')
			->with($provider, $clientSecretChanged, $settingsBefore, $settingsAfter);

		$response = $this->controller->updateProvider(3, 'keycloak', 'nextcloud', self::DISCOVERY_ENDPOINT, $clientSecret, ['checkBearer' => true]);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateUnknownProviderDoesNotLog(): void {
		$this->providerMapper->method('getProvider')->with(3)->willReturn($this->getProvider());
		$this->providerService->method('getProviderByIdentifier')->with('unknown')->willReturn(null);
		$this->providerMapper->expects($this->never())->method('update');
		$this->auditLogger->expects($this->never())->method('providerUpdated');

		$response = $this->controller->updateProvider(3, 'unknown', 'nextcloud', self::DISCOVERY_ENDPOINT);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDeleteProvider(): void {
		$provider = $this->getProvider();
		$this->providerMapper->method('getProvider')->with(3)->willReturn($provider);
		$this->providerMapper->expects($this->once())->method('delete')->with($provider);
		$this->providerService->expects($this->once())->method('deleteSettings')->with(3);
		$this->auditLogger->expects($this->once())->method('providerDeleted')->with($provider);

		$response = $this->controller->deleteProvider(3);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDeleteUnknownProviderDoesNotLog(): void {
		$this->providerMapper->method('getProvider')->with(3)->willThrowException(new DoesNotExistException(''));
		$this->auditLogger->expects($this->never())->method('providerDeleted');

		$response = $this->controller->deleteProvider(3);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
