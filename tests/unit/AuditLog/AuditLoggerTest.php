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
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(function (CriticalActionPerformedEvent $event): void {
				$this->event = $event;
			});
		$this->auditLogger = new AuditLogger($this->eventDispatcher);
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
		$this->auditLogger->providerUpdated($this->getProvider(), false, ['checkBearer' => true], ['checkBearer' => true]);

		$parameters = $this->event->getParameters();
		$this->assertFalse($parameters['clientSecretChanged']);
		$this->assertSame([], $parameters['changedSettings']);
	}

	public function testProviderDeleted(): void {
		$this->auditLogger->providerDeleted($this->getProvider());

		$this->assertOperation('user_oidc.provider.deleted');
		$this->assertSame('OpenID Connect provider "%s" with ID %s was deleted', $this->event->getLogMessage());
		$this->assertSame(['identifier' => 'keycloak', 'providerId' => 3], $this->event->getParameters());
	}

	/**
	 * Servers before the operation was added to CriticalActionPerformedEvent do not keep it
	 */
	private function assertOperation(string $operation): void {
		if (method_exists($this->event, 'getOperation')) {
			$this->assertSame($operation, $this->event->getOperation());
		}
	}
}
