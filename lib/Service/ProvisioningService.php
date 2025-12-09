<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use InvalidArgumentException;
use OC\Accounts\AccountManager;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Image;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\PreConditionNotMetException;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class ProvisioningService {

	public function __construct(
		private LocalIdService $idService,
		private ProviderService $providerService,
		private UserMapper $userMapper,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private IAccountManager $accountManager,
		private IClientService $clientService,
		private IAvatarManager $avatarManager,
		private IConfig $config,
		private ISession $session,
		private IFactory $l10nFactory,
		private ?CirclesService $circlesService = null,
	) {
	}

	public function hasOidcUserProvisitioned(string $userId): bool {
		try {
			$this->userMapper->getUser($userId);
			return true;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
		}
		return false;
	}

	/**
	 * Resolves a claim path like "custom.nickname" or multiple alternatives separated by "|".
	 * Returns the first found value, or null if none could be resolved.
	 */
	public function getClaimValues(object|array $tokenPayload, string $claimPath, int $providerId): mixed {
		if ($claimPath === '') {
			return null;
		}

		// Check config if dot-notation resolution is enabled
		$resolveDot = $this->providerService->getSetting($providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0') === '1';

		if (!$resolveDot) {
			// fallback to simple access
			if (is_object($tokenPayload) && property_exists($tokenPayload, $claimPath)) {
				return $tokenPayload->{$claimPath};
			} elseif (is_array($tokenPayload) && array_key_exists($claimPath, $tokenPayload)) {
				return $tokenPayload[$claimPath];
			}
			return null;
		}

		// Support alternatives separated by "|"
		$alternatives = explode('|', $claimPath);

		foreach ($alternatives as $altPath) {
			$parts = explode('.', trim($altPath));
			$value = $tokenPayload;

			foreach ($parts as $part) {
				if (is_object($value) && property_exists($value, $part)) {
					$value = $value->{$part};
				} elseif (is_array($value) && array_key_exists($part, $value)) {
					$value = $value[$part];
				} else {
					continue 2;
				}
			}

			return $value;
		}

		return null;
	}

	/**
	 * Resolves a claim path like "custom.nickname" or multiple alternatives separated by "|".
	 * Returns the first found string value, or null if none could be resolved.
	 */
	public function getClaimValue(object|array $tokenPayload, string $claimPath, int $providerId): mixed {
		$value = $this->getClaimValues($tokenPayload, $claimPath, $providerId);
		return is_string($value) ? $value : null;
	}

	/**
	 * @param string $tokenUserId
	 * @param int $providerId
	 * @param object $idTokenPayload
	 * @param IUser|null $existingLocalUser
	 * @return array{user: ?IUser, userData: array}
	 * @throws Exception
	 * @throws PropertyDoesNotExistException
	 * @throws PreConditionNotMetException
	 */
	public function provisionUser(string $tokenUserId, int $providerId, object $idTokenPayload, ?IUser $existingLocalUser = null): array {
		// user data potentially later used by globalsiteselector if user_oidc is used with global scale
		$oidcGssUserData = get_object_vars($idTokenPayload);

		// get name/email/quota information from the token itself
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$email = $this->getClaimValue($idTokenPayload, $emailAttribute, $providerId);//$idTokenPayload->{$emailAttribute} ?? null;

		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$userName = $this->getClaimValue($idTokenPayload, $displaynameAttribute, $providerId);//$idTokenPayload->{$displaynameAttribute} ?? null;

		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$quota = $this->getClaimValue($idTokenPayload, $quotaAttribute, $providerId);//$idTokenPayload->{$quotaAttribute} ?? null;

		$languageAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LANGUAGE, 'language');
		$language = $this->getClaimValue($idTokenPayload, $languageAttribute, $providerId);//$idTokenPayload->{$languageAttribute} ?? null;

		$localeAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LOCALE, 'locale');
		$locale = $this->getClaimValue($idTokenPayload, $localeAttribute, $providerId);

		$genderAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GENDER, 'gender');
		$gender = $this->getClaimValue($idTokenPayload, $genderAttribute, $providerId);//$idTokenPayload->{$genderAttribute} ?? null;

		$addressAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ADDRESS, 'address');
		$address = $this->getClaimValue($idTokenPayload, $addressAttribute, $providerId);//$idTokenPayload->{$addressAttribute} ?? null;

		$postalcodeAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_POSTALCODE, 'postal_code');
		$postalcode = $this->getClaimValue($idTokenPayload, $postalcodeAttribute, $providerId);//$idTokenPayload->{$postalcodeAttribute} ?? null;

		$streetAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_STREETADDRESS, 'street_address');
		$street = $this->getClaimValue($idTokenPayload, $streetAttribute, $providerId);//$idTokenPayload->{$streetAttribute} ?? null;

		$localityAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LOCALITY, 'locality');
		$locality = $this->getClaimValue($idTokenPayload, $localityAttribute, $providerId);//$idTokenPayload->{$localityAttribute} ?? null;

		$regionAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_REGION, 'region');
		$region = $this->getClaimValue($idTokenPayload, $regionAttribute, $providerId);//$idTokenPayload->{$regionAttribute} ?? null;

		$countryAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_COUNTRY, 'country');
		$country = $this->getClaimValue($idTokenPayload, $countryAttribute, $providerId);//$idTokenPayload->{$countryAttribute} ?? null;

		$websiteAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_WEBSITE, 'website');
		$website = $this->getClaimValue($idTokenPayload, $websiteAttribute, $providerId);//$idTokenPayload->{$websiteAttribute} ?? null;

		$avatarAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_AVATAR, 'avatar');
		$avatar = $this->getClaimValue($idTokenPayload, $avatarAttribute, $providerId);//$idTokenPayload->{$avatarAttribute} ?? null;

		$phoneAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_PHONE, 'phone_number');
		$phone = $this->getClaimValue($idTokenPayload, $phoneAttribute, $providerId);//$idTokenPayload->{$phoneAttribute} ?? null;

		$twitterAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_TWITTER, 'twitter');
		$twitter = $this->getClaimValue($idTokenPayload, $twitterAttribute, $providerId);//$idTokenPayload->{$twitterAttribute} ?? null;

		$fediverseAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_FEDIVERSE, 'fediverse');
		$fediverse = $this->getClaimValue($idTokenPayload, $fediverseAttribute, $providerId);//$idTokenPayload->{$fediverseAttribute} ?? null;

		$organisationAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ORGANISATION, 'organisation');
		$organisation = $this->getClaimValue($idTokenPayload, $organisationAttribute, $providerId);//$idTokenPayload->{$organisationAttribute} ?? null;

		$roleAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ROLE, 'role');
		$role = $this->getClaimValue($idTokenPayload, $roleAttribute, $providerId);//$idTokenPayload->{$roleAttribute} ?? null;

		$headlineAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_HEADLINE, 'headline');
		$headline = $this->getClaimValue($idTokenPayload, $headlineAttribute, $providerId);//$idTokenPayload->{$headlineAttribute} ?? null;

		$biographyAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_BIOGRAPHY, 'biography');
		$biography = $this->getClaimValue($idTokenPayload, $biographyAttribute, $providerId);//$idTokenPayload->{$biographyAttribute} ?? null;

		$pronounsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_PRONOUNS, 'pronouns');
		$pronouns = $idTokenPayload->{$pronounsAttribute} ?? null;

		$birthdateAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_BIRTHDATE, 'birthdate');
		$birthdate = $idTokenPayload->{$birthdateAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_UID, $idTokenPayload, $tokenUserId);
		$this->eventDispatcher->dispatchTyped($event);

		// use an existing user (from another backend) when soft auto provisioning is enabled
		if ($existingLocalUser !== null) {
			$user = $existingLocalUser;
		} else {
			// if disable_account_creation is true, user_oidc should not create any user
			// so we just exit
			// but it will accept connection from users it might have created in the past (before disable_account_creation was enabled)
			$oidcSystemConfig = $this->config->getSystemValue('junovy_user_oidc', []);
			$isUserCreationDisabled = isset($oidcSystemConfig['disable_account_creation'])
				&& in_array($oidcSystemConfig['disable_account_creation'], [true, 'true', 1, '1'], true);
			if ($isUserCreationDisabled) {
				return [
					'user' => null,
					'userData' => $oidcGssUserData,
				];
			}

			$backendUser = $this->userMapper->getOrCreate($providerId, $event->getValue() ?? '');
			$this->logger->debug('User obtained from the OIDC user backend: ' . $backendUser->getUserId());

			$user = $this->userManager->get($backendUser->getUserId());
			if ($user === null) {
				return [
					'user' => null,
					'userData' => $oidcGssUserData,
				];
			}
		}

		$account = $this->accountManager->getAccount($user);
		$fallbackScope = 'v2-local';
		$defaultScopes = array_merge(
			AccountManager::DEFAULT_SCOPES,
			$this->config->getSystemValue('account_manager.default_property_scope', []) ?? []
		);

		// Update displayname
		if (isset($userName)) {
			$newDisplayName = mb_substr($userName, 0, 255);
			$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $idTokenPayload, $newDisplayName);
		} else {
			$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $idTokenPayload);
		}
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Displayname mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$oidcGssUserData[$displaynameAttribute] = $event->getValue();
			$newDisplayName = $event->getValue();
			if ($existingLocalUser === null) {
				$oldDisplayName = $backendUser->getDisplayName();
				if ($newDisplayName !== $oldDisplayName) {
					$backendUser->setDisplayName($newDisplayName);
					$this->userMapper->update($backendUser);
				}
				// 2 reasons why we should update the display name: It does not match the one
				// - of our backend
				// - returned by the user manager (outdated one before the fix in https://github.com/nextcloud/user_oidc/pull/530)
				if ($newDisplayName !== $oldDisplayName || $newDisplayName !== $user->getDisplayName()) {
					$this->eventDispatcher->dispatchTyped(new UserChangedEvent($user, 'displayName', $newDisplayName, $oldDisplayName));
				}
			} else {
				$oldDisplayName = $user->getDisplayName();
				if ($newDisplayName !== $oldDisplayName) {
					$user->setDisplayName($newDisplayName);
					if ($user->getBackendClassName() === Application::APP_ID) {
						$backendUser = $this->userMapper->getOrCreate($providerId, $user->getUID());
						$backendUser->setDisplayName($newDisplayName);
						$this->userMapper->update($backendUser);
					}
					$this->eventDispatcher->dispatchTyped(new UserChangedEvent($user, 'displayName', $newDisplayName, $oldDisplayName));
				}
			}
		}

		// Update e-mail
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_EMAIL, $idTokenPayload, $email);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Email mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$oidcGssUserData[$emailAttribute] = $event->getValue();
			$user->setSystemEMailAddress($event->getValue());
		}

		// Update the quota
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $idTokenPayload, $quota);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Quota mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$oidcGssUserData[$quotaAttribute] = $event->getValue();
			$user->setQuota($event->getValue());
		}

		// Update groups
		if ($this->providerService->getSetting($providerId, ProviderService::SETTING_GROUP_PROVISIONING, '0') === '1') {
			$groups = $this->provisionUserGroups($user, $providerId, $idTokenPayload);
			// for gss
			if ($groups !== null) {
				$groupIds = array_map(static function ($group) {
					return $group->gid;
				}, $groups);
				$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
				$oidcGssUserData[$groupsAttribute] = $groupIds;
			}
		}

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_LOCALE, $idTokenPayload, $locale);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Locale mapping event dispatched');
		if ($event->hasValue()) {
			$locale = $event->getValue();
			$locales = $this->l10nFactory->findAvailableLocales();
			$localeCodes = array_map(static function ($l) {
				return $l['code'];
			}, $locales);
			if (in_array($locale, $localeCodes, true) || $locale === 'en') {
				$this->config->setUserValue($user->getUID(), 'core', 'locale', $locale);
			} else {
				$this->logger->debug('Invalid locale in ID token', ['locale' => $locale]);
			}
		}

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_LANGUAGE, $idTokenPayload, $language);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Language mapping event dispatched');
		if ($event->hasValue()) {
			$language = $event->getValue();
			$languagesCodes = $this->l10nFactory->findAvailableLanguages();
			if (in_array($language, $languagesCodes, true) || $language === 'en') {
				$this->config->setUserValue($user->getUID(), 'core', 'lang', $language);
			} else {
				$this->logger->debug('Invalid language in ID token', ['language' => $language]);
			}
		}

		$addressParts = null;
		if (is_object($address)) {
			// Update the location/address
			$addressArray = json_decode(json_encode($address), true);
			if (is_array($addressArray)
				&& (isset($addressArray[$streetAttribute]) || isset($addressArray[$postalcodeAttribute]) || isset($addressArray[$localityAttribute])
				|| isset($addressArray[$regionAttribute]) || isset($addressArray[$countryAttribute]))
			) {
				$addressParts = [
					$addressArray[$streetAttribute] ?? '',
					($addressArray[$postalcodeAttribute] ?? '') . ' ' . ($addressArray[$localityAttribute] ?? ''),
					$addressArray[$regionAttribute] ?? '',
					$addressArray[$countryAttribute] ?? '',
				];
			} else {
				$address = null;
			}
		} elseif ($street !== null || $postalcode !== null || $locality !== null || $region !== null || $country !== null) {
			// Concatenate the address components
			$addressParts = [
				$street ?? '',
				($postalcode ?? '') . ' ' . ($locality ?? ''),
				$region ?? '',
				$country ?? '',
			];
		}

		if ($addressParts !== null) {
			// concatenate them back together into a string and remove unused ', '
			$address = str_replace('  ', ' ', implode(', ', $addressParts));
		}

		// Update the avatar
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_AVATAR, $idTokenPayload, $avatar);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Avatar mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$this->setUserAvatar($user->getUID(), $event->getValue());
		}

		// Update the gender
		// Since until now there is no default for property for gender we have to use default
		// In v31 there will be introduced PRONOUNS, which could be of better use
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GENDER, $idTokenPayload, $gender);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Gender mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('gender', $event->getValue(), $fallbackScope, '1', '');
		}

		$simpleAccountPropertyAttributes = [
			IAccountManager::PROPERTY_PHONE => ['value' => $phone, 'setting_key' => ProviderService::SETTING_MAPPING_PHONE],
			IAccountManager::PROPERTY_ADDRESS => ['value' => $address, 'setting_key' => ProviderService::SETTING_MAPPING_PHONE],
			IAccountManager::PROPERTY_WEBSITE => ['value' => $website, 'setting_key' => ProviderService::SETTING_MAPPING_WEBSITE],
			IAccountManager::PROPERTY_TWITTER => ['value' => $twitter, 'setting_key' => ProviderService::SETTING_MAPPING_TWITTER],
			IAccountManager::PROPERTY_FEDIVERSE => ['value' => $fediverse, 'setting_key' => ProviderService::SETTING_MAPPING_FEDIVERSE],
			IAccountManager::PROPERTY_ORGANISATION => ['value' => $organisation, 'setting_key' => ProviderService::SETTING_MAPPING_ORGANISATION],
			IAccountManager::PROPERTY_ROLE => ['value' => $role, 'setting_key' => ProviderService::SETTING_MAPPING_ROLE],
			IAccountManager::PROPERTY_HEADLINE => ['value' => $headline, 'setting_key' => ProviderService::SETTING_MAPPING_HEADLINE],
			IAccountManager::PROPERTY_BIOGRAPHY => ['value' => $biography, 'setting_key' => ProviderService::SETTING_MAPPING_BIOGRAPHY],
		];
		// properties that appeared after 28 (our min supported NC version)
		if (defined(IAccountManager::class . '::PROPERTY_PRONOUNS')) {
			$simpleAccountPropertyAttributes[IAccountManager::PROPERTY_PRONOUNS] = ['value' => $pronouns, 'setting_key' => ProviderService::SETTING_MAPPING_PRONOUNS];
		}
		if (defined(IAccountManager::class . '::PROPERTY_BIRTHDATE')) {
			$simpleAccountPropertyAttributes[IAccountManager::PROPERTY_BIRTHDATE] = ['value' => $birthdate, 'setting_key' => ProviderService::SETTING_MAPPING_BIRTHDATE];
		}

		foreach ($simpleAccountPropertyAttributes as $property => $values) {
			$event = new AttributeMappedEvent($values['setting_key'], $idTokenPayload, $values['value']);
			$this->eventDispatcher->dispatchTyped($event);
			$this->logger->debug($property . ' mapping event dispatched');
			if ($event->hasValue()) {
				$account->setProperty($property, $event->getValue(), $defaultScopes[$property] ?? $fallbackScope, '1', '');
			}
		}

		while (true) {
			try {
				$this->accountManager->updateAccount($account);
				break;
			} catch (InvalidArgumentException $e) {
				// If the message is a property name, then this was throws because of an invalid property value
				if (in_array($e->getMessage(), IAccountManager::ALLOWED_PROPERTIES)) {
					$property = $account->getProperty($e->getMessage());
					// Remove the property from account
					$account->setProperty($property->getName(), '', $property->getScope(), IAccountManager::NOT_VERIFIED);
					$this->logger->info('Invalid account property provisioned', ['account' => $user->getUID(), 'property' => $property->getName()]);
					continue;
				}
				// unrelated error - rethrow
				throw $e;
			}
		}
		return [
			'user' => $user,
			'userData' => $oidcGssUserData,
		];
	}

	/**
	 * @param string $userId
	 * @param string $avatarAttribute
	 * @return void
	 */
	private function setUserAvatar(string $userId, string $avatarAttribute): void {
		$avatarContent = null;
		if (filter_var($avatarAttribute, FILTER_VALIDATE_URL)) {
			$client = $this->clientService->newClient();
			try {
				$avatarContent = $client->get($avatarAttribute)->getBody();
				if (is_resource($avatarContent)) {
					$avatarContent = stream_get_contents($avatarContent);
				}
				if ($avatarContent === false) {
					$this->logger->warning('Failed to read remote avatar response for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
					return;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Failed to get remote avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		} elseif (str_starts_with($avatarAttribute, 'data:image/png;base64,')) {
			$avatarContent = base64_decode(str_replace('data:image/png;base64,', '', $avatarAttribute));
			if ($avatarContent === false) {
				$this->logger->warning('Failed to decode base64 PNG avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		} elseif (str_starts_with($avatarAttribute, 'data:image/jpeg;base64,')) {
			$avatarContent = base64_decode(str_replace('data:image/jpeg;base64,', '', $avatarAttribute));
			if ($avatarContent === false) {
				$this->logger->warning('Failed to decode base64 JPEG avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		}

		if ($avatarContent === null || $avatarContent === '') {
			$this->logger->warning('Failed to set avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
			return;
		}

		try {
			// inspired from OC\Core\Controller\AvatarController::postAvatar()
			$image = new Image();
			$image->loadFromData($avatarContent);
			$image->readExif($avatarContent);
			$image->fixOrientation();

			if ($image->valid()) {
				$mimeType = $image->mimeType();
				if ($mimeType !== 'image/jpeg' && $mimeType !== 'image/png') {
					$this->logger->warning('Failed to set remote avatar for user ' . $userId, ['error' => 'Unknown filetype']);
					return;
				}

				if ($image->width() === $image->height()) {
					try {
						$avatar = $this->avatarManager->getAvatar($userId);
						$avatar->set($image);
						return;
					} catch (Throwable $e) {
						$this->logger->error('Failed to set remote avatar for user ' . $userId, ['exception' => $e]);
						return;
					}
				}
				$this->logger->warning('Failed to set remote avatar for user ' . $userId, ['error' => 'Image is not square']);
			} else {
				$this->logger->warning('Failed to set remote avatar for user ' . $userId, ['error' => 'Invalid image']);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to set remote avatar for user ' . $userId, ['exception' => $e]);
		}
	}

	public function getSyncGroupsOfToken(int $providerId, object $idTokenPayload) {
		$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
		$groupsData = $this->getClaimValues($idTokenPayload, $groupsAttribute, $providerId);

		$groupsWhitelistRegex = $this->getGroupWhitelistRegex($providerId);

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GROUPS, $idTokenPayload, json_encode($groupsData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Group mapping event dispatched');

		if ($event->hasValue() && $event->getValue() !== null) {
			// casted to null if empty value
			$groups = json_decode($event->getValue() ?? '');
			// support values like group1,group2
			if (is_string($groups)) {
				$groups = explode(',', $groups);
				// remove surrounding spaces in each group
				$groups = array_map('trim', $groups);
				// remove empty strings
				$groups = array_filter($groups);
			}
			$syncGroups = [];

			foreach ($groups as $k => $v) {
				if (is_object($v)) {
					// Handle array of objects, e.g. [{gid: "1", displayName: "group1"}, ...]
					if (empty($v->gid) && $v->gid !== '0' && $v->gid !== 0) {
						continue;
					}
					$group = $v;
				} elseif (is_string($v)) {
					// Handle array of strings, e.g. ["group1", "group2", ...]
					$group = (object)['gid' => $v, 'displayName' => $v];
				} else {
					continue;
				}

				if ($groupsWhitelistRegex) {
					$matchResult = preg_match($groupsWhitelistRegex, $group->gid);
					if ($matchResult !== 1) {
						if ($matchResult === 0) {
							$this->logger->debug('Skipped group `' . $group->gid . '` for importing as not part of whitelist (not matching the regex)');
						} else {
							$this->logger->debug('Skipped group `' . $group->gid . '` for importing as not part of whitelist (failure when matching)', ['match_result' => $matchResult]);
						}
						continue;
					}
				}

				// Note: Do NOT hash group IDs like user IDs. Group names from the IdP
				// should be used as-is to maintain readability and proper group membership.
				// The idService->getId() was incorrectly hashing group names with SHA256
				// when SETTING_UNIQUE_UID was enabled.

				$syncGroups[] = $group;
			}

			return $syncGroups;
		}

		return null;
	}

	public function provisionUserGroups(IUser $user, int $providerId, object $idTokenPayload): ?array {
		$groupsWhitelistRegex = $this->getGroupWhitelistRegex($providerId);

		$syncGroups = $this->getSyncGroupsOfToken($providerId, $idTokenPayload);

		if ($syncGroups === null) {
			return null;
		}

		$protectedGroups = $this->getProtectedGroups($providerId);
		$userGroups = $this->groupManager->getUserGroups($user);
		foreach ($userGroups as $group) {
			if (!in_array($group->getGID(), array_column($syncGroups, 'gid'))) {
				// Skip protected groups - never remove users from these groups
				if (in_array($group->getGID(), $protectedGroups)) {
					$this->logger->debug('Skipped removing user from protected group `' . $group->getGID() . '`');
					continue;
				}
				if ($groupsWhitelistRegex && !preg_match($groupsWhitelistRegex, $group->getGID())) {
					continue;
				}
				$group->removeUser($user);
			}
		}

		foreach ($syncGroups as $group) {
			// Creates a new group or return the exiting one.
			if ($newGroup = $this->groupManager->createGroup($group->gid)) {
				// Adds the user to the group. Does nothing if user is already in the group.
				$newGroup->addUser($user);

				if (isset($group->displayName)) {
					$newGroup->setDisplayName($group->displayName);
				}
			}
		}

		return $syncGroups;
	}


	public function getGroupWhitelistRegex(int $providerId): string {
		$regex = $this->providerService->getSetting($providerId, ProviderService::SETTING_GROUP_WHITELIST_REGEX, '');

		// If regex does not start with '/', add '/' to the beginning and end
		// Only check first character to allow for flags at the end of the regex
		if ($regex && substr($regex, 0, 1) !== '/') {
			$regex = '/' . $regex . '/';
		}

		return $regex;
	}

	/**
	 * Get list of protected groups that should never have users removed from them
	 * These are Nextcloud system groups that are not managed by OIDC/Keycloak
	 *
	 * @param int $providerId The provider ID
	 * @return array Array of protected group IDs
	 */
	private function getProtectedGroups(int $providerId): array {
		$protectedGroupsSetting = $this->providerService->getSetting($providerId, ProviderService::SETTING_PROTECTED_GROUPS, 'users,admin');

		if (empty($protectedGroupsSetting)) {
			return ['users', 'admin'];
		}

		// Split comma-separated string and trim whitespace
		$groups = array_map('trim', explode(',', $protectedGroupsSetting));
		// Filter out empty values
		$groups = array_filter($groups, function ($group) {
			return !empty($group);
		});

		// Return default if no valid groups found
		if (empty($groups)) {
			return ['users', 'admin'];
		}

		return array_values($groups);
	}

	/**
	 * Get the Teams whitelist regex for filtering organization-based circles
	 */
	public function getTeamsWhitelistRegex(int $providerId): string {
		$regex = $this->providerService->getSetting($providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '');

		// If regex does not start with '/', add '/' to the beginning and end
		if ($regex && substr($regex, 0, 1) !== '/') {
			$regex = '/' . $regex . '/';
		}

		return $regex;
	}

	/**
	 * Get organizations from the ID token payload
	 * Supports both Keycloak Organizations format and simple array format
	 *
	 * Keycloak Organizations format:
	 * {
	 *   "organizations": {
	 *     "org-id-1": { "name": "Engineering", "roles": ["member", "admin"] },
	 *     "org-id-2": { "name": "Marketing", "roles": ["member"] }
	 *   }
	 * }
	 *
	 * Simple array format:
	 * { "organizations": ["org1", "org2"] }
	 *
	 * @param int $providerId The provider ID
	 * @param object $idTokenPayload The ID token payload
	 * @return array|null Array of organization objects with 'id' and 'name', or null if not available
	 */
	public function getOrganizationsFromToken(int $providerId, object $idTokenPayload): ?array {
		$orgAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations');
		$orgData = $this->getClaimValues($idTokenPayload, $orgAttribute, $providerId);

		$teamsWhitelistRegex = $this->getTeamsWhitelistRegex($providerId);

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ORGANIZATIONS, $idTokenPayload, json_encode($orgData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Organization mapping event dispatched');

		if (!$event->hasValue() || $event->getValue() === null) {
			return null;
		}

		$orgsRaw = json_decode($event->getValue() ?? '', true);
		if ($orgsRaw === null) {
			return null;
		}

		$organizations = [];

		// Handle Keycloak Organizations format (object with org IDs as keys)
		if (is_array($orgsRaw) && !array_is_list($orgsRaw)) {
			foreach ($orgsRaw as $orgId => $orgData) {
				$orgName = is_array($orgData) && isset($orgData['name']) ? $orgData['name'] : $orgId;
				$org = (object)[
					'id' => $orgId,
					'name' => $orgName,
					'roles' => is_array($orgData) && isset($orgData['roles']) ? $orgData['roles'] : [],
				];

				// Apply whitelist regex
				if ($teamsWhitelistRegex) {
					$matchResult = preg_match($teamsWhitelistRegex, $org->name);
					if ($matchResult !== 1) {
						$this->logger->debug('Skipped organization `' . $org->name . '` for Teams provisioning (not matching whitelist regex)');
						continue;
					}
				}

				$organizations[] = $org;
			}
		} else {
			// Handle simple array format (list of strings)
			foreach ($orgsRaw as $orgName) {
				if (!is_string($orgName)) {
					continue;
				}

				$org = (object)[
					'id' => $orgName,
					'name' => $orgName,
					'roles' => [],
				];

				// Apply whitelist regex
				if ($teamsWhitelistRegex) {
					$matchResult = preg_match($teamsWhitelistRegex, $org->name);
					if ($matchResult !== 1) {
						$this->logger->debug('Skipped organization `' . $org->name . '` for Teams provisioning (not matching whitelist regex)');
						continue;
					}
				}

				$organizations[] = $org;
			}
		}

		return count($organizations) > 0 ? $organizations : null;
	}

	/**
	 * Provision user's Teams/Circles based on their organization memberships
	 * Creates circles if they don't exist, adds user to circles they should belong to,
	 * and removes them from circles they no longer belong to (within whitelist scope)
	 *
	 * @param IUser $user The user to provision teams for
	 * @param int $providerId The provider ID
	 * @param object $idTokenPayload The ID token payload containing organization claims
	 * @return array|null Array of provisioned organizations, or null if Teams provisioning is disabled
	 */
	public function provisionUserTeams(IUser $user, int $providerId, object $idTokenPayload): ?array {
		// Check if CirclesService is available
		if ($this->circlesService === null) {
			$this->logger->debug('CirclesService not available, skipping Teams provisioning');
			return null;
		}

		// Check if Circles app is enabled
		if (!$this->circlesService->isCirclesEnabled()) {
			$this->logger->debug('Circles app is not enabled, skipping Teams provisioning');
			return null;
		}

		$teamsWhitelistRegex = $this->getTeamsWhitelistRegex($providerId);
		$syncOrganizations = $this->getOrganizationsFromToken($providerId, $idTokenPayload);

		if ($syncOrganizations === null) {
			$this->logger->debug('No organizations found in token for user ' . $user->getUID());
			return null;
		}

		$this->logger->info('Provisioning Teams for user ' . $user->getUID() . ' with ' . count($syncOrganizations) . ' organizations');

		// Get user's current circles for cleanup
		$currentCircles = $this->circlesService->getUserCircles($user);
		$currentCircleNames = [];
		foreach ($currentCircles as $circle) {
			$currentCircleNames[] = $circle->getDisplayName();
		}

		// Track which circles the user should be in
		$targetCircleNames = array_map(function ($org) {
			return $org->name;
		}, $syncOrganizations);

		// Remove user from circles they no longer belong to (within whitelist scope)
		foreach ($currentCircles as $circle) {
			$circleName = $circle->getDisplayName();

			// Skip if circle is in target list
			if (in_array($circleName, $targetCircleNames)) {
				continue;
			}

			// Skip if circle doesn't match whitelist (we only manage whitelisted circles)
			if ($teamsWhitelistRegex && !preg_match($teamsWhitelistRegex, $circleName)) {
				continue;
			}

			// Remove user from circle
			$this->circlesService->removeMember($circle, $user);
		}

		// Add user to circles they should belong to
		foreach ($syncOrganizations as $org) {
			// Create or get the circle
			$circle = $this->circlesService->getOrCreateCircle($org->id, $org->name);
			if ($circle === null) {
				$this->logger->warning('Failed to get or create circle for organization: ' . $org->name);
				continue;
			}

			// Add user to circle
			$this->circlesService->addMember($circle, $user);
		}

		return $syncOrganizations;
	}
}
