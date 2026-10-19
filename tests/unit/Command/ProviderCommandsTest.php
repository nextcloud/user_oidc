<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Command\DeleteProvider;
use OCA\UserOIDC\Command\UpsertProvider;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

class ProviderCommandsTest extends TestCase {
	private ProviderService&MockObject $providerService;
	private ProviderMapper&MockObject $providerMapper;
	private ICrypto&MockObject $crypto;
	private AuditLogger&MockObject $auditLogger;

	protected function setUp(): void {
		parent::setUp();

		$this->providerService = $this->createMock(ProviderService::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);

		$this->crypto->method('encrypt')->willReturnCallback(static fn (string $secret): string => 'encrypted-' . $secret);
	}

	private function getProvider(): Provider {
		$provider = new Provider();
		$provider->setId(3);
		$provider->setIdentifier('keycloak');
		$provider->setClientId('nextcloud');
		$provider->setClientSecret('encrypted-old-secret');
		$provider->setDiscoveryEndpoint('https://idp.example.com/.well-known/openid-configuration');
		$provider->setScope('openid email profile');
		return $provider;
	}

	private function getUpsertTester(): CommandTester {
		return new CommandTester(new UpsertProvider($this->providerService, $this->providerMapper, $this->crypto, $this->auditLogger));
	}

	public function testUpsertCreatesProvider(): void {
		$provider = $this->getProvider();
		$this->providerService->method('getProviderByIdentifier')->with('keycloak')->willReturn(null);
		$this->providerMapper->expects($this->once())
			->method('createOrUpdateProvider')
			->with('keycloak', 'nextcloud', 'encrypted-secret', 'https://idp.example.com/.well-known/openid-configuration')
			->willReturn($provider);
		$this->providerService->expects($this->never())->method('getSettings');

		$this->auditLogger->expects($this->once())->method('providerCreated')->with($provider);
		$this->auditLogger->expects($this->never())->method('providerUpdated');

		$tester = $this->getUpsertTester();
		$tester->execute([
			'identifier' => 'keycloak',
			'--clientid' => 'nextcloud',
			'--clientsecret' => 'secret',
			'--discoveryuri' => 'https://idp.example.com/.well-known/openid-configuration',
		]);
		$this->assertSame(0, $tester->getStatusCode());
	}

	public static function dataUpsertUpdatesProvider(): array {
		return [
			'with client secret' => [['--clientsecret' => 'new-secret'], true],
			'without client secret' => [['--check-bearer' => '1'], false],
		];
	}

	#[DataProvider('dataUpsertUpdatesProvider')]
	public function testUpsertUpdatesProvider(array $options, bool $clientSecretChanged): void {
		$provider = $this->getProvider();
		$this->providerService->method('getProviderByIdentifier')->with('keycloak')->willReturn($provider);
		$this->providerMapper->method('createOrUpdateProvider')->willReturn($provider);
		$settingsBefore = ['checkBearer' => false];
		$settingsAfter = ['checkBearer' => true];
		$this->providerService->method('getSettings')->with(3)->willReturnOnConsecutiveCalls($settingsBefore, $settingsAfter);

		$this->auditLogger->expects($this->never())->method('providerCreated');
		$this->auditLogger->expects($this->once())
			->method('providerUpdated')
			->with($provider, $clientSecretChanged, $settingsBefore, $settingsAfter);

		$tester = $this->getUpsertTester();
		$tester->execute(['identifier' => 'keycloak'] + $options);
		$this->assertSame(0, $tester->getStatusCode());
	}

	public function testUpsertShowingProviderDoesNotLog(): void {
		$provider = $this->getProvider();
		$this->providerMapper->method('findProviderByIdentifier')->with('keycloak')->willReturn($provider);
		$this->providerService->method('getProviderWithSettings')->with(3)->willReturn(['id' => 3, 'settings' => []]);
		$this->providerMapper->expects($this->never())->method('createOrUpdateProvider');
		$this->auditLogger->expects($this->never())->method('providerCreated');
		$this->auditLogger->expects($this->never())->method('providerUpdated');

		$tester = $this->getUpsertTester();
		$tester->execute(['identifier' => 'keycloak', '--output' => 'json']);
		$this->assertSame(0, $tester->getStatusCode());
	}

	public function testUpsertFailingToCreateProviderDoesNotLog(): void {
		$this->providerService->method('getProviderByIdentifier')->willReturn(null);
		$this->providerMapper->method('createOrUpdateProvider')
			->willThrowException(new DoesNotExistException('Provider must be created. All provider parameters required.'));
		$this->auditLogger->expects($this->never())->method('providerCreated');
		$this->auditLogger->expects($this->never())->method('providerUpdated');

		$tester = $this->getUpsertTester();
		$tester->execute(['identifier' => 'keycloak', '--clientid' => 'nextcloud']);
		$this->assertSame(-1, $tester->getStatusCode());
	}

	private function getDeleteTester(): CommandTester {
		$command = new DeleteProvider($this->providerMapper, $this->providerService, $this->auditLogger);
		$command->setHelperSet(new HelperSet([new QuestionHelper()]));
		return new CommandTester($command);
	}

	public function testDeleteProvider(): void {
		$provider = $this->getProvider();
		$this->providerMapper->method('findProviderByIdentifier')->with('keycloak')->willReturn($provider);
		$this->providerMapper->expects($this->once())->method('delete')->with($provider);
		$this->providerService->expects($this->once())->method('deleteSettings')->with(3);
		$this->auditLogger->expects($this->once())->method('providerDeleted')->with($provider);

		$tester = $this->getDeleteTester();
		$tester->execute(['identifier' => 'keycloak', '--force' => true]);
		$this->assertSame(0, $tester->getStatusCode());
	}

	public function testDeleteProviderNotConfirmedDoesNotLog(): void {
		$this->providerMapper->method('findProviderByIdentifier')->with('keycloak')->willReturn($this->getProvider());
		$this->providerMapper->expects($this->never())->method('delete');
		$this->auditLogger->expects($this->never())->method('providerDeleted');

		$tester = $this->getDeleteTester();
		$tester->setInputs(['n']);
		$tester->execute(['identifier' => 'keycloak']);
		$this->assertSame(0, $tester->getStatusCode());
	}

	public function testDeleteUnknownProviderDoesNotLog(): void {
		$this->providerMapper->method('findProviderByIdentifier')->willThrowException(new DoesNotExistException(''));
		$this->auditLogger->expects($this->never())->method('providerDeleted');

		$tester = $this->getDeleteTester();
		$tester->execute(['identifier' => 'unknown', '--force' => true]);
		$this->assertSame(-1, $tester->getStatusCode());
	}
}
