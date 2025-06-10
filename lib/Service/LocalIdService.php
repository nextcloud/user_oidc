<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\ProviderMapper;

class LocalIdService {

	public function __construct(
		private ProviderService $providerService,
		private ProviderMapper $providerMapper,
	) {
	}

	public function getId(int $providerId, string $id, bool $id4me = false): string {
		if ($this->providerService->getSetting($providerId, ProviderService::SETTING_UNIQUE_UID, '1') === '1' || $id4me) {
			$newId = strval($providerId) . '_';

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
