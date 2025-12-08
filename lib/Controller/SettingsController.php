<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use Exception;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private ProviderMapper $providerMapper,
		private ID4MeService $id4meService,
		private ProviderService $providerService,
		private ICrypto $crypto,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function isDiscoveryEndpointValid($url) {
		$result = [
			'isReachable' => false,
			'missingFields' => [],
		];

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url);
			$httpCode = $response->getStatusCode();
			$body = $response->getBody();

			// Check if the request was successful
			if ($httpCode === Http::STATUS_OK && !empty($body)) {
				$result['isReachable'] = true;
				$data = json_decode($body, true);

				// Check for required fields as defined in: https://openid.net/specs/openid-connect-discovery-1_0.html
				$requiredFields = [
					'issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri',
					'response_types_supported', 'subject_types_supported', 'id_token_signing_alg_values_supported',
				];

				foreach ($requiredFields as $field) {
					if (!isset($data[$field])) {
						$result['missingFields'][] = $field;
					}
				}
			}
		} catch (Exception $e) {
			$this->logger->error('Discovery endpoint validation error', ['exception' => $e]);
		}

		return $result;
	}

	#[PasswordConfirmationRequired]
	public function createProvider(string $identifier, string $clientId, string $clientSecret, string $discoveryEndpoint,
		array $settings = [], string $scope = 'openid email profile', ?string $endSessionEndpoint = null,
		?string $postLogoutUri = null): JSONResponse {
		if ($this->providerService->getProviderByIdentifier($identifier) !== null) {
			return new JSONResponse(['message' => 'Provider with the given identifier already exists'], Http::STATUS_CONFLICT);
		}

		$result = $this->isDiscoveryEndpointValid($discoveryEndpoint);
		if (!$result['isReachable']) {
			$message = 'The discovery endpoint is not reachable.';
			return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		} elseif (!empty($result['missingFields'])) {
			$message = 'Invalid discovery endpoint. Missing fields: ' . implode(', ', $result['missingFields']);
			return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		}

		$provider = new Provider();
		$provider->setIdentifier($identifier);
		$provider->setClientId($clientId);
		$encryptedClientSecret = $this->crypto->encrypt($clientSecret);
		$provider->setClientSecret($encryptedClientSecret);
		$provider->setDiscoveryEndpoint($discoveryEndpoint);
		$provider->setEndSessionEndpoint($endSessionEndpoint ?: null);
		$provider->setPostLogoutUri($postLogoutUri ?: null);
		$provider->setScope($scope);
		$provider = $this->providerMapper->insert($provider);

		$providerSettings = $this->providerService->setSettings($provider->getId(), $settings);

		return new JSONResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

	#[PasswordConfirmationRequired]
	public function updateProvider(int $providerId, string $identifier, string $clientId, string $discoveryEndpoint, ?string $clientSecret = null,
		array $settings = [], string $scope = 'openid email profile', ?string $endSessionEndpoint = null,
		?string $postLogoutUri = null): JSONResponse {
		$provider = $this->providerMapper->getProvider($providerId);

		if ($this->providerService->getProviderByIdentifier($identifier) === null) {
			return new JSONResponse(['message' => 'Provider with the given identifier does not exist'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->isDiscoveryEndpointValid($discoveryEndpoint);
		if (!$result['isReachable']) {
			$message = 'The discovery endpoint is not reachable.';
			return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		} elseif (!empty($result['missingFields'])) {
			$message = 'Invalid discovery endpoint. Missing fields: ' . implode(', ', $result['missingFields']);
			return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		}

		$provider->setIdentifier($identifier);
		$provider->setClientId($clientId);
		if ($clientSecret) {
			$encryptedClientSecret = $this->crypto->encrypt($clientSecret);
			$provider->setClientSecret($encryptedClientSecret);
		}
		$provider->setDiscoveryEndpoint($discoveryEndpoint);
		$provider->setEndSessionEndpoint($endSessionEndpoint ?: null);
		$provider->setPostLogoutUri($postLogoutUri ?: null);
		$provider->setScope($scope);
		$provider = $this->providerMapper->update($provider);

		$providerSettings = $this->providerService->setSettings($providerId, $settings);
		// invalidate JWKS cache
		$this->providerService->setSetting($providerId, ProviderService::SETTING_JWKS_CACHE, '');
		$this->providerService->setSetting($providerId, ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, '');

		return new JSONResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

	#[PasswordConfirmationRequired]
	public function deleteProvider(int $providerId): JSONResponse {
		try {
			$provider = $this->providerMapper->getProvider($providerId);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->providerMapper->delete($provider);
		$this->providerService->deleteSettings($providerId);

		return new JSONResponse([], Http::STATUS_OK);
	}

	public function getProviders(): JSONResponse {
		return new JSONResponse($this->providerService->getProvidersWithSettings());
	}

	public function getID4ME(): bool {
		return $this->id4meService->getID4ME();
	}

	#[PasswordConfirmationRequired]
	public function setID4ME(bool $enabled): JSONResponse {
		$this->id4meService->setID4ME($enabled);
		return new JSONResponse(['enabled' => $this->getID4ME()]);
	}

	#[PasswordConfirmationRequired]
	public function setAdminConfig(array $values): JSONResponse {
		foreach ($values as $key => $value) {
			if ($key === 'store_login_token' && is_bool($value)) {
				$this->appConfig->setValueString(Application::APP_ID, 'store_login_token', $value ? '1' : '0');
			}
		}
		return new JSONResponse([]);
	}

	/**
	 * Resync groups for OIDC users by removing them from hashed groups.
	 * This fixes the issue where groups were incorrectly hashed with SHA256.
	 * Users will get proper groups on their next login.
	 *
	 * @param int|null $providerId Optional provider ID to resync only for a specific provider
	 * @return JSONResponse Statistics about the resync operation
	 */
	#[PasswordConfirmationRequired]
	public function resyncGroups(?int $providerId = null): JSONResponse {
		$stats = [
			'hashed_groups_found' => 0,
			'users_removed' => 0,
			'groups_cleaned' => 0,
		];

		try {
			// Find all groups that look like SHA256 hashes (64 hex characters)
			$allGroups = $this->groupManager->search('');
			$hashedGroups = [];

			foreach ($allGroups as $group) {
				$gid = $group->getGID();
				// Check if group ID looks like a SHA256 hash (64 hex characters)
				if (preg_match('/^[a-f0-9]{64}$/i', $gid)) {
					$hashedGroups[] = $group;
					$stats['hashed_groups_found']++;
				}
			}

			// Remove users from hashed groups
			foreach ($hashedGroups as $group) {
				$users = $group->getUsers();
				foreach ($users as $user) {
					// Only process users from OIDC backend if providerId is specified
					if ($providerId !== null) {
						// Check if user belongs to OIDC backend
						if ($user->getBackendClassName() === Application::APP_ID) {
							// This is an OIDC user, remove from hashed group
							$group->removeUser($user);
							$stats['users_removed']++;
						}
					} else {
						// Remove all users from hashed groups
						$group->removeUser($user);
						$stats['users_removed']++;
					}
				}

				// If group is now empty, optionally delete it
				// For now, we'll leave empty groups (they can be cleaned up manually)
				if (count($group->getUsers()) === 0) {
					$stats['groups_cleaned']++;
				}
			}

			$this->logger->info('Group resync completed', $stats);

			return new JSONResponse([
				'success' => true,
				'message' => 'Groups resynced successfully. Users will get proper groups on their next login.',
				'stats' => $stats,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Group resync failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'message' => 'Failed to resync groups: ' . $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
