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

class SettingsController extends Controller {

	/** @var ProviderMapper */
	private $providerMapper;
	/**
	 * @var ID4MeService
	 */
	private $id4meService;
	/**
	 * @var ProviderService
	 */
	private $providerService;

	public function __construct(
		IRequest $request,
		ProviderMapper $providerMapper,
		ID4MeService $id4meService,
		ProviderService $providerService
		) {
		parent::__construct(Application::APP_ID, $request);

		$this->providerMapper = $providerMapper;
		$this->id4meService = $id4meService;
		$this->providerService = $providerService;
	}

	public function createProvider(string $identifier, string $clientId, string $clientSecret, string $discoveryEndpoint, array $settings = []): JSONResponse {
		$provider = new Provider();
		$provider->setIdentifier($identifier);
		$provider->setClientId($clientId);
		$provider->setClientSecret($clientSecret);
		$provider->setDiscoveryEndpoint($discoveryEndpoint);

		$provider = $this->providerMapper->insert($provider);

		$providerSettings = $this->providerService->setSettings($provider->getId(), $settings);

		return new JSONResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

	public function updateProvider(int $providerId, string $identifier, string $clientId, string $discoveryEndpoint, string $clientSecret = null, array $settings = []): JSONResponse {
		$provider = $this->providerMapper->getProvider($providerId);
		$provider->setIdentifier($identifier);
		$provider->setClientId($clientId);
		if ($clientSecret) {
			$provider->setClientSecret($clientSecret);
		}
		$provider->setDiscoveryEndpoint($discoveryEndpoint);

		$provider = $this->providerMapper->update($provider);

		$providerSettings = $this->providerService->setSettings($providerId, $settings);

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

	public function getID4ME(): JSONResponse {
		return new JSONResponse($this->id4meService->getID4ME());
	}

	public function setID4ME(bool $enabled): JSONResponse {
		$this->id4meService->setID4ME($enabled);
		return $this->getID4ME();
	}
}
