<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\Db\Provider;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase {
	private IEventDispatcher&MockObject $eventDispatcher;
	private AuditLogger $auditLogger;
	private ?CriticalActionPerformedEvent $event = null;

	protected function setUp(): void {
		parent::setUp();

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->auditLogger = new AuditLogger($this->eventDispatcher);
	}

	private function expectEvent(): void {
		if (!method_exists(CriticalActionPerformedEvent::class, 'getOperation')) {
			$this->markTestSkipped('Requires Nextcloud 36 or later');
		}
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (CriticalActionPerformedEvent $event): void {
				$this->event = $event;
			});
	}

	private function getProvider(): Provider {
		$provider = new Provider();
		$provider->setId(3);
		$provider->setIdentifier('keycloak');
		$provider->setClientId('nextcloud');
		$provider->setClientSecret('encrypted-secret');
		$provider->setDiscoveryEndpoint('https://idp.example.com/.well-known/openid-configuration');
		return $provider;
	}

	public function testProviderCreated(): void {
		$this->expectEvent();
		$this->auditLogger->providerCreated($this->getProvider());

		$this->assertOperation('user_oidc.provider.created');
		$this->assertSame('OpenID Connect provider "%s" with ID %s was created', $this->event->getLogMessage());
		$this->assertSame([
			'identifier' => 'keycloak',
			'providerId' => 3,
			'clientId' => 'nextcloud',
			'discoveryEndpoint' => 'https://idp.example.com/.well-known/openid-configuration',
		], $this->event->getParameters());
	}

	public function testProviderUpdatedListsChangedSettingsOnly(): void {
		$this->expectEvent();
		$this->auditLogger->providerUpdated(
			$this->getProvider(),
			true,
			['checkBearer' => false, 'groupWhitelistRegex' => '', 'mappingUid' => 'sub'],
			['checkBearer' => true, 'groupWhitelistRegex' => '/^admins$/', 'mappingUid' => 'sub'],
		);

		$this->assertOperation('user_oidc.provider.updated');
		$this->assertSame('OpenID Connect provider "%s" with ID %s was updated', $this->event->getLogMessage());
		$this->assertSame([
			'identifier' => 'keycloak',
			'providerId' => 3,
			'clientId' => 'nextcloud',
			'discoveryEndpoint' => 'https://idp.example.com/.well-known/openid-configuration',
			'clientSecretChanged' => true,
			'changedSettings' => ['checkBearer', 'groupWhitelistRegex'],
		], $this->event->getParameters());
	}

	public function testProviderUpdatedWithoutChanges(): void {
		$this->expectEvent();
		$this->auditLogger->providerUpdated($this->getProvider(), false, ['checkBearer' => true], ['checkBearer' => true]);

		$parameters = $this->event->getParameters();
		$this->assertFalse($parameters['clientSecretChanged']);
		$this->assertSame([], $parameters['changedSettings']);
	}

	public function testProviderDeleted(): void {
		$this->expectEvent();
		$this->auditLogger->providerDeleted($this->getProvider());

		$this->assertOperation('user_oidc.provider.deleted');
		$this->assertSame('OpenID Connect provider "%s" with ID %s was deleted', $this->event->getLogMessage());
		$this->assertSame(['identifier' => 'keycloak', 'providerId' => 3], $this->event->getParameters());
	}

	public function testNothingIsLoggedBeforeNextcloud36(): void {
		if (method_exists(CriticalActionPerformedEvent::class, 'getOperation')) {
			$this->markTestSkipped('Only applies before Nextcloud 36');
		}
		$this->eventDispatcher->expects($this->never())->method('dispatchTyped');

		$this->auditLogger->providerCreated($this->getProvider());
	}

	private function assertOperation(string $operation): void {
		$this->assertSame($operation, $this->event->getOperation());
	}
}
