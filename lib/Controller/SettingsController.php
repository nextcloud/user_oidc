<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\ICrypto;

class SettingsController extends Controller {

	/** @var ProviderMapper */
	private $providerMapper;
	/** @var ID4MeService */
	private $id4meService;
	/** @var ProviderService */
	private $providerService;
	/** @var ICrypto */
	private $crypto;

	public function __construct(
		IRequest $request,
		ProviderMapper $providerMapper,
		ID4MeService $id4meService,
		ProviderService $providerService,
		ICrypto $crypto
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->providerMapper = $providerMapper;
		$this->id4meService = $id4meService;
		$this->providerService = $providerService;
		$this->crypto = $crypto;
	}

	public function isDiscoveryEndpointValid($url) {
		// Initialize cURL session
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, false);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
	
		$result = [
			'isReachable' => false,
			'missingFields' => [],
		];
	
		// Check if the request was successful
		if ($httpCode == 200 && !empty($response)) {
			$result['isReachable'] = true;
			$data = json_decode($response, true);
	
			// Check for necessary fields in the response
			$requiredFields = [
				'issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri',
				'response_types_supported', 'subject_types_supported', 'id_token_signing_alg_values_supported',
			];
			
			foreach ($requiredFields as $field) {
				if (!isset($data[$field])) {
					$result['missingFields'][] = $field;
				}
			}
		} else {
			// Set isReachable to false if http code wasnt 200
			$result['isReachable'] = false;
		}
	
		return $result;
	}

	public function createProvider(string $identifier, string $clientId, string $clientSecret, string $discoveryEndpoint,
		array $settings = [], string $scope = 'openid email profile', ?string $endSessionEndpoint = null): JSONResponse {
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
		$provider->setScope($scope);
		$provider = $this->providerMapper->insert($provider);

		$providerSettings = $this->providerService->setSettings($provider->getId(), $settings);

		return new JSONResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

	public function updateProvider(int $providerId, string $identifier, string $clientId, string $discoveryEndpoint, string $clientSecret = null,
		array $settings = [], string $scope = 'openid email profile', ?string $endSessionEndpoint = null): JSONResponse {
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
		$provider->setScope($scope);
		$provider = $this->providerMapper->update($provider);

		$providerSettings = $this->providerService->setSettings($providerId, $settings);
		// invalidate JWKS cache
		$this->providerService->setSetting($providerId, ProviderService::SETTING_JWKS_CACHE, '');
		$this->providerService->setSetting($providerId, ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, '');

		return new JSONResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

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

	public function setID4ME(bool $enabled): JSONResponse {
		$this->id4meService->setID4ME($enabled);
		return new JSONResponse(['enabled' => $this->getID4ME()]);
	}
}
