<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\AuditLog;

use OCA\UserOIDC\Db\Provider;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

class AuditLogger {

	public function __construct(
		private IEventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * Does nothing on servers before Nextcloud 36, whose CriticalActionPerformedEvent has no operation
	 *
	 * @param array<string, mixed> $parameters named values for the placeholders of $message, in order, optionally followed by more context
	 */
	public function log(string $operation, string $message, array $parameters = []): void {
		if (!method_exists(CriticalActionPerformedEvent::class, 'getOperation')) {
			return;
		}
		$arguments = [$message, $parameters, false, $operation];
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(...$arguments));
	}

	public function providerCreated(Provider $provider): void {
		$this->log(Operation::PROVIDER_CREATED, 'OpenID Connect provider "%s" with ID %s was created', [
			'identifier' => $provider->getIdentifier(),
			'providerId' => $provider->getId(),
			'clientId' => $provider->getClientId(),
			'discoveryEndpoint' => $provider->getDiscoveryEndpoint(),
		]);
	}

	/**
	 * Only logs whether the client secret was set and which settings changed, never their values.
	 *
	 * @param array<string, mixed> $settingsBefore
	 * @param array<string, mixed> $settingsAfter
	 */
	public function providerUpdated(Provider $provider, bool $clientSecretChanged, array $settingsBefore, array $settingsAfter): void {
		$changedSettings = [];
		foreach ($settingsAfter as $key => $value) {
			if (!array_key_exists($key, $settingsBefore) || $settingsBefore[$key] !== $value) {
				$changedSettings[] = $key;
			}
		}

		$this->log(Operation::PROVIDER_UPDATED, 'OpenID Connect provider "%s" with ID %s was updated', [
			'identifier' => $provider->getIdentifier(),
			'providerId' => $provider->getId(),
			'clientId' => $provider->getClientId(),
			'discoveryEndpoint' => $provider->getDiscoveryEndpoint(),
			'clientSecretChanged' => $clientSecretChanged,
			'changedSettings' => $changedSettings,
		]);
	}

	public function providerDeleted(Provider $provider): void {
		$this->log(Operation::PROVIDER_DELETED, 'OpenID Connect provider "%s" with ID %s was deleted', [
			'identifier' => $provider->getIdentifier(),
			'providerId' => $provider->getId(),
		]);
	}

	/**
	 * @param 'provisioning_api'|'bearer_token'|'id4me_login' $source
	 */
	public function userProvisioned(string $userId, int $providerId, string $source): void {
		$this->log(Operation::USER_PROVISIONED, 'User %s was provisioned by OpenID Connect provider %s', [
			'userId' => $userId,
			'providerId' => $providerId,
			'source' => $source,
		]);
	}
}
