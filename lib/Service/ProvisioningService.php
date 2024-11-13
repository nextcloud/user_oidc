<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCP\Accounts\IAccountManager;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Image;
use OCP\IUser;
use OCP\IUserManager;
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
	) {
	}

	/**
	 * @param string $tokenUserId
	 * @param int $providerId
	 * @param object $idTokenPayload
	 * @param IUser|null $existingLocalUser
	 * @return IUser|null
	 * @throws Exception
	 */
	public function provisionUser(string $tokenUserId, int $providerId, object $idTokenPayload, ?IUser $existingLocalUser = null): ?IUser {
		// get name/email/quota information from the token itself
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$email = $idTokenPayload->{$emailAttribute} ?? null;

		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$userName = $idTokenPayload->{$displaynameAttribute} ?? null;

		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$quota = $idTokenPayload->{$quotaAttribute} ?? null;

		$genderAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GENDER, 'gender');
		$gender = $idTokenPayload->{$genderAttribute} ?? null;

		$addressAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ADDRESS, 'address');
		$address = $idTokenPayload->{$addressAttribute} ?? null;

		$postalcodeAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_POSTALCODE, 'postal_code');
		$postalcode = $idTokenPayload->{$postalcodeAttribute} ?? null;

		$streetAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_STREETADDRESS, 'street_address');
		$street = $idTokenPayload->{$streetAttribute} ?? null;

		$localityAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LOCALITY, 'locality');
		$locality = $idTokenPayload->{$localityAttribute} ?? null;

		$regionAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_REGION, 'region');
		$region = $idTokenPayload->{$regionAttribute} ?? null;

		$countryAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_COUNTRY, 'country');
		$country = $idTokenPayload->{$countryAttribute} ?? null;

		$websiteAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_WEBSITE, 'website');
		$website = $idTokenPayload->{$websiteAttribute} ?? null;

		$avatarAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_AVATAR, 'avatar');
		$avatar = $idTokenPayload->{$avatarAttribute} ?? null;

		$phoneAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_PHONE, 'phone_number');
		$phone = $idTokenPayload->{$phoneAttribute} ?? null;

		$twitterAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_TWITTER, 'twitter');
		$twitter = $idTokenPayload->{$twitterAttribute} ?? null;

		$fediverseAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_FEDIVERSE, 'fediverse');
		$fediverse = $idTokenPayload->{$fediverseAttribute} ?? null;

		$organisationAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ORGANISATION, 'organisation');
		$organisation = $idTokenPayload->{$organisationAttribute} ?? null;

		$roleAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ROLE, 'role');
		$role = $idTokenPayload->{$roleAttribute} ?? null;

		$headlineAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_HEADLINE, 'headline');
		$headline = $idTokenPayload->{$headlineAttribute} ?? null;

		$biographyAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_BIOGRAPHY, 'biography');
		$biography = $idTokenPayload->{$biographyAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_UID, $idTokenPayload, $tokenUserId);
		$this->eventDispatcher->dispatchTyped($event);

		// use an existing user (from another backend) when soft auto provisioning is enabled
		if ($existingLocalUser !== null) {
			$user = $existingLocalUser;
		} else {
			// if disable_account_creation is true, user_oidc should not create any user
			// so we just exit
			// but it will accept connection from users it might have created in the past (before disable_account_creation was enabled)
			$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
			$isUserCreationDisabled = isset($oidcSystemConfig['disable_account_creation'])
				&& in_array($oidcSystemConfig['disable_account_creation'], [true, 'true', 1, '1'], true);
			if ($isUserCreationDisabled) {
				return null;
			}

			$backendUser = $this->userMapper->getOrCreate($providerId, $event->getValue() ?? '');
			$this->logger->debug('User obtained from the OIDC user backend: ' . $backendUser->getUserId());

			$user = $this->userManager->get($backendUser->getUserId());
			if ($user === null) {
				return null;
			}
		}

		$account = $this->accountManager->getAccount($user);
		$scope = 'v2-local';

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
				}
			}
		}

		// Update e-mail
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_EMAIL, $idTokenPayload, $email);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Email mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$user->setSystemEMailAddress($event->getValue());
		}

		// Update the quota
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $idTokenPayload, $quota);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Quota mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$user->setQuota($event->getValue());
		}

		// Update groups
		if ($this->providerService->getSetting($providerId, ProviderService::SETTING_GROUP_PROVISIONING, '0') === '1') {
			$this->provisionUserGroups($user, $providerId, $idTokenPayload);
		}

		// Update the phone number
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_PHONE, $idTokenPayload, $phone);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Phone mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('phone', $event->getValue(), $scope, '1', '');
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

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ADDRESS, $idTokenPayload, $address);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Address mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('address', $event->getValue(), $scope, '1', '');
		}

		// Update the website
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_WEBSITE, $idTokenPayload, $website);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Website mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('website', $event->getValue(), $scope, '1', '');
		}

		// Update the avatar
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_AVATAR, $idTokenPayload, $avatar);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Avatar mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$this->setUserAvatar($user->getUID(), $event->getValue());
		}

		// Update twitter/X
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_TWITTER, $idTokenPayload, $twitter);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Twitter mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('twitter', $event->getValue(), $scope, '1', '');
		}

		// Update fediverse
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_FEDIVERSE, $idTokenPayload, $fediverse);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Fediverse mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('fediverse', $event->getValue(), $scope, '1', '');
		}

		// Update the organisation
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ORGANISATION, $idTokenPayload, $organisation);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Organisation mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('organisation', $event->getValue(), $scope, '1', '');
		}

		// Update role
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ROLE, $idTokenPayload, $role);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Role mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('role', $event->getValue(), '1', '');
		}

		// Update the headline
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_HEADLINE, $idTokenPayload, $headline);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Headline mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('headline', $event->getValue(), $scope, '1', '');
		}

		// Update the biography
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_BIOGRAPHY, $idTokenPayload, $biography);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Biography mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('biography', $event->getValue(), $scope, '1', '');
		}

		// Update the gender
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GENDER, $idTokenPayload, $gender);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Gender mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('gender', $event->getValue(), $scope, '1', '');
		}

		$this->accountManager->updateAccount($account);
		return $user;
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
		$groupsData = $idTokenPayload->{$groupsAttribute} ?? null;

		$groupsWhitelistRegex = $this->getGroupWhitelistRegex($providerId);

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GROUPS, $idTokenPayload, json_encode($groupsData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Group mapping event dispatched');

		if ($event->hasValue() && $event->getValue() !== null) {
			// casted to null if empty value
			$groups = json_decode($event->getValue() ?? '');
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

				if ($groupsWhitelistRegex && !preg_match($groupsWhitelistRegex, $group->gid)) {
					$this->logger->debug('Skipped group `' . $group->gid . '` for importing as not part of whitelist');
					continue;
				}

				$group->gid = $this->idService->getId($providerId, $group->gid);

				$syncGroups[] = $group;
			}

			return $syncGroups;
		}

		return null;
	}

	public function provisionUserGroups(IUser $user, int $providerId, object $idTokenPayload): void {
		$groupsWhitelistRegex = $this->getGroupWhitelistRegex($providerId);

		$syncGroups = $this->getSyncGroupsOfToken($providerId, $idTokenPayload);

		if ($syncGroups !== null) {

			$userGroups = $this->groupManager->getUserGroups($user);
			foreach ($userGroups as $group) {
				if (!in_array($group->getGID(), array_column($syncGroups, 'gid'))) {
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
		}
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
}
