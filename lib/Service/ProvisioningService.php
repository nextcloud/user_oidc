<?php

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCP\Accounts\IAccountManager;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

class ProvisioningService {
	/** @var UserMapper */
	private $userMapper;

	/** @var LocalIdService */
	private $idService;

	/** @var IUserManager */
	private $userManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var LoggerInterface */
	private $logger;

	/** @var ProviderService */
	private $providerService;

	/** @var IAccountManager */
	private $accountManager;


	public function __construct(
		LocalIdService $idService,
		ProviderService $providerService,
		UserMapper $userMapper,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IEventDispatcher $eventDispatcher,
		LoggerInterface $logger,
		IAccountManager $accountManager
	) {
		$this->idService = $idService;
		$this->providerService = $providerService;
		$this->userMapper = $userMapper;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
		$this->accountManager = $accountManager;
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
		if ($event->hasValue()) {
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
		if ($event->hasValue()) {
			$user->setEMailAddress($event->getValue());
		}

		// Update the quota
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $idTokenPayload, $quota);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Quota mapping event dispatched');
		if ($event->hasValue()) {
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
			$account->setProperty('phone', $phone, $scope, '1', '');
		}

		if (is_object($address)) {
			// Update the location/address
			$addressArray = json_decode(json_encode($address), true);
			$addressParts = [
				$addressArray[$streetAttribute],
				$addressArray[$postalcodeAttribute] . ' ' . $addressArray[$localityAttribute],
				$addressArray[$regionAttribute],
				$addressArray[$countryAttribute]
			];
		} else {
			// Concatenate the address components
			$addressParts = [
				$street,
				$postalcode . ' ' . $locality,
				$region,
				$country
			];
		}

		// concatenate them back together into a string and remove unsused ', '
		$address = str_replace('  ', ' ', implode(', ', $addressParts));

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ADDRESS, $idTokenPayload, $address);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Address mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('address', $address, $scope, '1', '');
		}

		// Update the website
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_WEBSITE, $idTokenPayload, $website);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Website mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('website', $website, $scope, '1', '');
		}

		// Update the avatar
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_AVATAR, $idTokenPayload, $avatar);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Avatar mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('avatar', $avatar, $scope, '1', '');
		}

		// Update twitter/X
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_TWITTER, $idTokenPayload, $twitter);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Twitter mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('twitter', $twitter, $scope, '1', '');
		}

		// Update fediverse
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_FEDIVERSE, $idTokenPayload, $fediverse);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Fediverse mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('fediverse', $fediverse, $scope, '1', '');
		}

		// Update the organisation
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ORGANISATION, $idTokenPayload, $organisation);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Organisation mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('organisation', $organisation, $scope, '1', '');
		}

		// Update role
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ROLE, $idTokenPayload, $role);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Role mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('role', $role, $scope, '1', '');
		}

		// Update the headline
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_HEADLINE, $idTokenPayload, $headline);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Headline mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('headline', $headline, $scope, '1', '');
		}

		// Update the biography
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_BIOGRAPHY, $idTokenPayload, $biography);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Biography mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('biography', $biography, $scope, '1', '');
		}

		// Update the gender
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GENDER, $idTokenPayload, $gender);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Gender mapping event dispatched');
		if ($event->hasValue()) {
			$account->setProperty('gender', $gender, $scope, '1', '');
		}

		$this->accountManager->updateAccount($account);
		return $user;
	}

	public function provisionUserGroups(IUser $user, int $providerId, object $idTokenPayload): void {
		$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
		$groupsData = $idTokenPayload->{$groupsAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GROUPS, $idTokenPayload, json_encode($groupsData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Group mapping event dispatched');

		if ($event->hasValue() && $event->getValue() !== null) {
			// casted to null if empty value
			$groups = json_decode($event->getValue() ?? '');
			$userGroups = $this->groupManager->getUserGroups($user);
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

				$group->gid = $this->idService->getId($providerId, $group->gid);

				$syncGroups[] = $group;
			}

			foreach ($userGroups as $group) {
				if (!in_array($group->getGID(), array_column($syncGroups, 'gid'))) {
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
}
