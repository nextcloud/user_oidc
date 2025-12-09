<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;

class ProviderService {
	public const SETTING_CHECK_BEARER = 'checkBearer';
	public const SETTING_SEND_ID_TOKEN_HINT = 'sendIdTokenHint';
	public const SETTING_BEARER_PROVISIONING = 'bearerProvisioning';
	public const SETTING_UNIQUE_UID = 'uniqueUid';
	public const SETTING_MAPPING_UID = 'mappingUid';
	public const SETTING_MAPPING_UID_DEFAULT = 'sub';
	public const SETTING_MAPPING_DISPLAYNAME = 'mappingDisplayName';
	public const SETTING_MAPPING_EMAIL = 'mappingEmail';
	public const SETTING_MAPPING_QUOTA = 'mappingQuota';
	public const SETTING_MAPPING_GROUPS = 'mappingGroups';
	public const SETTING_MAPPING_LANGUAGE = 'mappingLanguage';
	public const SETTING_MAPPING_LOCALE = 'mappingLocale';
	public const SETTING_MAPPING_ADDRESS = 'mappingAddress';
	public const SETTING_MAPPING_STREETADDRESS = 'mappingStreetaddress';
	public const SETTING_MAPPING_POSTALCODE = 'mappingPostalcode';
	public const SETTING_MAPPING_LOCALITY = 'mappingLocality';
	public const SETTING_MAPPING_REGION = 'mappingRegion';
	public const SETTING_MAPPING_COUNTRY = 'mappingCountry';
	public const SETTING_MAPPING_WEBSITE = 'mappingWebsite';
	public const SETTING_MAPPING_AVATAR = 'mappingAvatar';
	public const SETTING_MAPPING_TWITTER = 'mappingTwitter';
	public const SETTING_MAPPING_FEDIVERSE = 'mappingFediverse';
	public const SETTING_MAPPING_ORGANISATION = 'mappingOrganisation';
	public const SETTING_MAPPING_ROLE = 'mappingRole';
	public const SETTING_MAPPING_HEADLINE = 'mappingHeadline';
	public const SETTING_MAPPING_BIOGRAPHY = 'mappingBiography';
	public const SETTING_MAPPING_PHONE = 'mappingPhonenumber';
	public const SETTING_MAPPING_GENDER = 'mappingGender';
	public const SETTING_MAPPING_PRONOUNS = 'mappingPronouns';
	public const SETTING_MAPPING_BIRTHDATE = 'mappingBirthdate';
	public const SETTING_EXTRA_CLAIMS = 'extraClaims';
	public const SETTING_JWKS_CACHE = 'jwksCache';
	public const SETTING_JWKS_CACHE_TIMESTAMP = 'jwksCacheTimestamp';
	public const SETTING_PROVIDER_BASED_ID = 'providerBasedId';
	public const SETTING_GROUP_PROVISIONING = 'groupProvisioning';
	public const SETTING_GROUP_WHITELIST_REGEX = 'groupWhitelistRegex';
	public const SETTING_RESTRICT_LOGIN_TO_GROUPS = 'restrictLoginToGroups';
	public const SETTING_PROTECTED_GROUPS = 'protectedGroups';
	public const SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING = 'nestedAndFallbackClaims';

	// Teams/Circles provisioning settings (from Keycloak Organizations)
	public const SETTING_TEAMS_PROVISIONING = 'teamsProvisioning';
	public const SETTING_MAPPING_ORGANIZATIONS = 'mappingOrganizations';
	public const SETTING_TEAMS_WHITELIST_REGEX = 'teamsWhitelistRegex';

	// URL Override settings
	public const SETTING_OVERRIDE_JWKS_URI = 'overrideJwksUri';
	public const SETTING_OVERRIDE_TOKEN_ENDPOINT = 'overrideTokenEndpoint';
	public const SETTING_OVERRIDE_USERINFO_ENDPOINT = 'overrideUserinfoEndpoint';

	// Missing configuration options
	public const SETTING_AUTO_REDIRECT = 'autoRedirect';
	public const SETTING_HIDE_PASSWORD_FORM = 'hidePasswordForm';
	public const SETTING_REDIRECT_FALLBACK = 'redirectFallback';
	public const SETTING_DISABLE_REGISTRATION = 'disableRegistration';
	public const SETTING_WEBDAV_ENABLED = 'webdavEnabled';
	public const SETTING_PASSWORD_AUTHENTICATION = 'passwordAuthentication';
	public const SETTING_USE_ID_TOKEN = 'useIdToken';
	public const SETTING_PUBLIC_KEY_CACHING_TIME = 'publicKeyCachingTime';
	public const SETTING_MIN_TIME_BETWEEN_JWKS_REQUESTS = 'minTimeBetweenJwksRequests';
	public const SETTING_WELL_KNOWN_CACHING_TIME = 'wellKnownCachingTime';
	public const SETTING_UPDATE_AVATAR = 'updateAvatar';
	public const SETTING_BUTTON_TEXT = 'buttonText';
	public const SETTING_ALT_LOGIN_PAGE = 'altLoginPage';
	public const SETTING_TLS_VERIFY = 'tlsVerify';
	public const SETTING_USE_EXTERNAL_STORAGE = 'useExternalStorage';
	public const SETTING_PROXY_LDAP = 'proxyLdap';

	public const BOOLEAN_SETTINGS_DEFAULT_VALUES = [
		self::SETTING_GROUP_PROVISIONING => false,
		self::SETTING_PROVIDER_BASED_ID => false,
		self::SETTING_BEARER_PROVISIONING => false,
		self::SETTING_UNIQUE_UID => true,
		self::SETTING_CHECK_BEARER => false,
		self::SETTING_SEND_ID_TOKEN_HINT => false,
		self::SETTING_RESTRICT_LOGIN_TO_GROUPS => false,
		self::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING => false,
		self::SETTING_AUTO_REDIRECT => false,
		self::SETTING_HIDE_PASSWORD_FORM => false,
		self::SETTING_REDIRECT_FALLBACK => false,
		self::SETTING_DISABLE_REGISTRATION => false,
		self::SETTING_WEBDAV_ENABLED => true,
		self::SETTING_PASSWORD_AUTHENTICATION => true,
		self::SETTING_USE_ID_TOKEN => true,
		self::SETTING_UPDATE_AVATAR => false,
		self::SETTING_TLS_VERIFY => true,
		self::SETTING_USE_EXTERNAL_STORAGE => false,
		self::SETTING_PROXY_LDAP => false,
		self::SETTING_TEAMS_PROVISIONING => false,
	];

	public function __construct(
		private IAppConfig $appConfig,
		private ProviderMapper $providerMapper,
		private IConfig $config,
	) {
	}

	public function getProvidersWithSettings(): array {
		$providers = $this->providerMapper->getProviders();
		return array_map(function ($provider) {
			$providerSettings = $this->getSettings($provider->getId());
			return array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]);
		}, $providers);
	}

	public function getProviderByIdentifier(string $identifier): ?Provider {
		try {
			return $this->providerMapper->findProviderByIdentifier($identifier);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function getProviderWithSettings(int $id): array {
		$provider = $this->providerMapper->getProvider($id);
		$providerSettings = $this->getSettings($provider->getId());
		return array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]);
	}

	public function getSettings(int $providerId): array {
		$result = [];
		foreach ($this->getSupportedSettings() as $setting) {
			$value = $this->getSetting($providerId, $setting);
			$result[$setting] = $this->convertToJSON($setting, $value);
		}
		return $result;
	}

	public function setSettings(int $providerId, array $settings): array {
		$storedSettings = $this->getSettings($providerId);
		foreach ($settings as $setting => $value) {
			if (!in_array($setting, $this->getSupportedSettings(), true)) {
				continue;
			}
			$this->setSetting($providerId, $setting, $this->convertFromJSON($setting, $value));
			$storedSettings[$setting] = $value;
		}
		return $storedSettings;
	}

	public function deleteSettings(int $providerId): void {
		foreach ($this->getSupportedSettings() as $setting) {
			$this->appConfig->deleteKey(Application::APP_ID, $this->getSettingsKey($providerId, $setting));
		}
		$this->appConfig->deleteKey(Application::APP_ID, $this->getSettingsKey($providerId, self::SETTING_JWKS_CACHE));
		$this->appConfig->deleteKey(Application::APP_ID, $this->getSettingsKey($providerId, self::SETTING_JWKS_CACHE_TIMESTAMP));
	}

	public function setSetting(int $providerId, string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $this->getSettingsKey($providerId, $key), $value);
	}

	public function getSetting(int $providerId, string $key, string $default = ''): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $this->getSettingsKey($providerId, $key), '');
		if ($value === '') {
			return $default;
		}
		return $value;
	}

	/**
	 * Get configuration value with precedence: per-provider setting > global config > default
	 *
	 * @param int $providerId Provider ID
	 * @param string $key Setting key
	 * @param mixed $default Default value if not found
	 * @return mixed Configuration value
	 */
	public function getConfigValue(int $providerId, string $key, $default = null) {
		// First check per-provider setting
		$providerValue = $this->getSetting($providerId, $key, '');
		if ($providerValue !== '') {
			// Convert boolean settings
			if (array_key_exists($key, self::BOOLEAN_SETTINGS_DEFAULT_VALUES)) {
				return $providerValue === '1';
			}
			// Convert integer settings
			if (in_array($key, [
				self::SETTING_PUBLIC_KEY_CACHING_TIME,
				self::SETTING_MIN_TIME_BETWEEN_JWKS_REQUESTS,
				self::SETTING_WELL_KNOWN_CACHING_TIME,
			], true)) {
				return (int)$providerValue;
			}
			return $providerValue;
		}

		// Then check global config.php
		$oidcConfig = $this->config->getSystemValue('junovy_user_oidc', []);

		// Try various key formats that might be used in config.php
		// Convert camelCase to snake_case for oidc_login compatibility
		$snakeKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
		$oidcKey = 'oidc_' . $snakeKey;
		$camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));

		// Check in order: oidc_snake_case, camelCase, original key
		if (isset($oidcConfig[$oidcKey])) {
			return $oidcConfig[$oidcKey];
		}
		if (isset($oidcConfig[$camelKey])) {
			return $oidcConfig[$camelKey];
		}
		if (isset($oidcConfig[$key])) {
			return $oidcConfig[$key];
		}

		// Return default
		if ($default !== null) {
			return $default;
		}

		// Return boolean default if it's a boolean setting
		if (array_key_exists($key, self::BOOLEAN_SETTINGS_DEFAULT_VALUES)) {
			return self::BOOLEAN_SETTINGS_DEFAULT_VALUES[$key];
		}

		return null;
	}

	private function getSettingsKey(int $providerId, string $key): string {
		return 'provider-' . strval($providerId) . '-' . $key;
	}

	private function getSupportedSettings(): array {
		return [
			self::SETTING_MAPPING_DISPLAYNAME,
			self::SETTING_MAPPING_EMAIL,
			self::SETTING_MAPPING_QUOTA,
			self::SETTING_MAPPING_UID,
			self::SETTING_MAPPING_GROUPS,
			self::SETTING_MAPPING_LANGUAGE,
			self::SETTING_MAPPING_LOCALE,
			self::SETTING_MAPPING_ADDRESS,
			self::SETTING_MAPPING_STREETADDRESS,
			self::SETTING_MAPPING_POSTALCODE,
			self::SETTING_MAPPING_LOCALITY,
			self::SETTING_MAPPING_REGION,
			self::SETTING_MAPPING_COUNTRY,
			self::SETTING_MAPPING_WEBSITE,
			self::SETTING_MAPPING_AVATAR,
			self::SETTING_MAPPING_TWITTER,
			self::SETTING_MAPPING_FEDIVERSE,
			self::SETTING_MAPPING_ORGANISATION,
			self::SETTING_MAPPING_ROLE,
			self::SETTING_MAPPING_HEADLINE,
			self::SETTING_MAPPING_BIOGRAPHY,
			self::SETTING_MAPPING_PHONE,
			self::SETTING_MAPPING_GENDER,
			self::SETTING_MAPPING_PRONOUNS,
			self::SETTING_MAPPING_BIRTHDATE,
			self::SETTING_UNIQUE_UID,
			self::SETTING_CHECK_BEARER,
			self::SETTING_SEND_ID_TOKEN_HINT,
			self::SETTING_BEARER_PROVISIONING,
			self::SETTING_EXTRA_CLAIMS,
			self::SETTING_PROVIDER_BASED_ID,
			self::SETTING_GROUP_PROVISIONING,
			self::SETTING_GROUP_WHITELIST_REGEX,
			self::SETTING_RESTRICT_LOGIN_TO_GROUPS,
			self::SETTING_PROTECTED_GROUPS,
			self::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING,
			// Teams/Circles provisioning
			self::SETTING_TEAMS_PROVISIONING,
			self::SETTING_MAPPING_ORGANIZATIONS,
			self::SETTING_TEAMS_WHITELIST_REGEX,
			// URL Overrides
			self::SETTING_OVERRIDE_JWKS_URI,
			self::SETTING_OVERRIDE_TOKEN_ENDPOINT,
			self::SETTING_OVERRIDE_USERINFO_ENDPOINT,
			// Missing configuration options
			self::SETTING_AUTO_REDIRECT,
			self::SETTING_HIDE_PASSWORD_FORM,
			self::SETTING_REDIRECT_FALLBACK,
			self::SETTING_DISABLE_REGISTRATION,
			self::SETTING_WEBDAV_ENABLED,
			self::SETTING_PASSWORD_AUTHENTICATION,
			self::SETTING_USE_ID_TOKEN,
			self::SETTING_PUBLIC_KEY_CACHING_TIME,
			self::SETTING_MIN_TIME_BETWEEN_JWKS_REQUESTS,
			self::SETTING_WELL_KNOWN_CACHING_TIME,
			self::SETTING_UPDATE_AVATAR,
			self::SETTING_BUTTON_TEXT,
			self::SETTING_ALT_LOGIN_PAGE,
			self::SETTING_TLS_VERIFY,
			self::SETTING_USE_EXTERNAL_STORAGE,
			self::SETTING_PROXY_LDAP,
		];
	}

	private function convertFromJSON(string $key, $value): string {
		if (array_key_exists($key, self::BOOLEAN_SETTINGS_DEFAULT_VALUES)) {
			return $value ? '1' : '0';
		}
		return (string)$value;
	}

	private function convertToJSON(string $key, $value) {
		if (array_key_exists($key, self::BOOLEAN_SETTINGS_DEFAULT_VALUES)) {
			if ($value === '') {
				return self::BOOLEAN_SETTINGS_DEFAULT_VALUES[$key];
			}
			return $value === '1';
		}
		return (string)$value;
	}
}
