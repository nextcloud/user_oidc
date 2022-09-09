<?php

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\ProviderMapper;

class IdService {
	/** @var ProviderService */
	private $providerService;

	/** @var ProviderMapper */
	private $providerMapper;

	public function __construct(ProviderService $providerService, ProviderMapper $providerMapper) {
		$this->providerService = $providerService;
		$this->providerMapper = $providerMapper;
	}

	public function getId(int $providerId, string $id, bool $id4me = false): string {
		if ($this->providerService->getSetting($providerId, ProviderService::SETTING_UNIQUE_UID, '1') === '1' || $id4me) {
			$newId = $providerId . '_';

			if ($id4me) {
				$newId .= '1_';
			} else {
				$newId .= '0_';
			}

			$newId .= $id;
			$newId = hash('sha256', $newId);
		} elseif ($this->providerService->getSetting($providerId, ProviderService::SETTING_PROVIDER_BASED_ID, '0') === '1') {
			$providerName = $this->providerMapper->getProvider($providerId)->getIdentifier();
			$newId = $providerName . '-' . $id;
		} else {
			$newId = $id;
		}

		return $newId;
	}
}
