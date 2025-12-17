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
		// equivalent of $this->providerService->getSupportedSettings()
		$supportedSettingKeys = [
			ProviderService::SETTING_MAPPING_DISPLAYNAME,
			ProviderService::SETTING_MAPPING_EMAIL,
			ProviderService::SETTING_MAPPING_QUOTA,
			ProviderService::SETTING_MAPPING_UID,
			ProviderService::SETTING_MAPPING_GROUPS,
			ProviderService::SETTING_MAPPING_LANGUAGE,
			ProviderService::SETTING_MAPPING_LOCALE,
			ProviderService::SETTING_MAPPING_ADDRESS,
			ProviderService::SETTING_MAPPING_STREETADDRESS,
			ProviderService::SETTING_MAPPING_POSTALCODE,
			ProviderService::SETTING_MAPPING_LOCALITY,
			ProviderService::SETTING_MAPPING_REGION,
			ProviderService::SETTING_MAPPING_COUNTRY,
			ProviderService::SETTING_MAPPING_WEBSITE,
			ProviderService::SETTING_MAPPING_AVATAR,
			ProviderService::SETTING_MAPPING_TWITTER,
			ProviderService::SETTING_MAPPING_FEDIVERSE,
			ProviderService::SETTING_MAPPING_ORGANISATION,
			ProviderService::SETTING_MAPPING_ROLE,
			ProviderService::SETTING_MAPPING_HEADLINE,
			ProviderService::SETTING_MAPPING_BIOGRAPHY,
			ProviderService::SETTING_MAPPING_PHONE,
			ProviderService::SETTING_MAPPING_GENDER,
			ProviderService::SETTING_MAPPING_PRONOUNS,
			ProviderService::SETTING_MAPPING_BIRTHDATE,
			ProviderService::SETTING_UNIQUE_UID,
			ProviderService::SETTING_CHECK_BEARER,
			ProviderService::SETTING_SEND_ID_TOKEN_HINT,
			ProviderService::SETTING_BEARER_PROVISIONING,
			ProviderService::SETTING_EXTRA_CLAIMS,
			ProviderService::SETTING_PROVIDER_BASED_ID,
			ProviderService::SETTING_GROUP_PROVISIONING,
			ProviderService::SETTING_GROUP_WHITELIST_REGEX,
			ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS,
			ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING,
		];
		$supportedSettingKeys[] = ProviderService::SETTING_JWKS_CACHE;
		$supportedSettingKeys[] = ProviderService::SETTING_JWKS_CACHE_TIMESTAMP;
		foreach ($supportedSettingKeys as $key) {
			foreach ($providers as $provider) {
				// equivalent of $this->providerService->getSettingsKey($provider->getId(), $key)
				$realKey = 'provider-' . strval($provider->getId()) . '-' . $key;
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
