<?php

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;

class ProvisioningService {
	/** @var UserMapper */
	private $userMapper;

	/** @var IdService */
	private $idService;

	/** @var IUserManager */
	private $userManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var ILogger */
	private $logger;

	/** @var ProviderService */
	private $providerService;

	public function __construct(
		IdService $idService,
		ProviderService $providerService,
		UserMapper $userMapper,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IEventDispatcher $eventDispatcher,
		ILogger $logger
	) {
		$this->idService = $idService;
		$this->providerService = $providerService;
		$this->userMapper = $userMapper;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
	}

	public function provisionUser(string $userId, int $providerId, object $idTokenPayload): ?IUser {
		// get name/email/quota information from the token itself
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$email = $idTokenPayload->{$emailAttribute} ?? null;
		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$userName = $idTokenPayload->{$displaynameAttribute} ?? null;
		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$quota = $idTokenPayload->{$quotaAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_UID, $idTokenPayload, $userId);
		$this->eventDispatcher->dispatchTyped($event);

		$backendUser = $this->userMapper->getOrCreate($providerId, $event->getValue());
		$this->logger->debug('User obtained from the OIDC user backend: ' . $backendUser->getUserId());

		$user = $this->userManager->get($backendUser->getUserId());
		if ($user === null) {
			return $user;
		}

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
			if ($newDisplayName != $backendUser->getDisplayName()) {
				$backendUser->setDisplayName($newDisplayName);
				$backendUser = $this->userMapper->update($backendUser);
			}
		}

		// Update e-mail
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_EMAIL, $idTokenPayload, $email);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Email mapping event dispatched');
		if ($event->hasValue()) {
			$user->setSystemEMailAddress($event->getValue());
		}

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

		return $user;
	}

	public function provisionUserGroups(IUser $user, int $providerId, object $idTokenPayload): void {
		$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
		$groupsData = $idTokenPayload->{$groupsAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GROUPS, $idTokenPayload, json_encode($groupsData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Group mapping event dispatched');

		if ($event->hasValue()) {
			$groups = json_decode($event->getValue());
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
					$group = (object)array('gid' => $v);
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
				if ($newGroup = $this->groupManager->createGroup($group->gid)) {
					$newGroup->addUser($user);

					if (isset($group->displayName)) {
						$newGroup->setDisplayName($group->displayName);
					}
				}
			}
		}
	}
}
