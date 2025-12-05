<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version080101Date20251201121749 extends SimpleMigrationStep {

	public function __construct(
		private IAppConfig $appConfig,
		private ProviderService $providerService,
		private ProviderMapper $providerMapper,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// make admin settings lazy
		$keys = [
			'store_login_token',
			'id4me_enabled',
			'allow_multiple_user_backends',
		];
		foreach ($keys as $key) {
			try {
				if ($this->appConfig->hasKey(Application::APP_ID, $key)) {
					$value = $this->appConfig->getValueString(Application::APP_ID, $key);
					$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true);
				}
			} catch (\Exception) {
			}
		}

		// make all provider settings lazy
		$providers = $this->providerMapper->getProviders();
		$supportedSettingKeys = $this->providerService->getSupportedSettings();
		$supportedSettingKeys[] = ProviderService::SETTING_JWKS_CACHE;
		$supportedSettingKeys[] = ProviderService::SETTING_JWKS_CACHE_TIMESTAMP;
		foreach ($supportedSettingKeys as $key) {
			foreach ($providers as $provider) {
				$realKey = $this->providerService->getSettingsKey($provider->getId(), $key);
				if ($this->appConfig->hasKey(Application::APP_ID, $realKey)) {
					try {
						$value = $this->appConfig->getValueString(Application::APP_ID, $realKey);
						$this->appConfig->setValueString(Application::APP_ID, $realKey, $value, lazy: true);
					} catch (\Exception) {
					}
				}
			}
		}
	}
}
