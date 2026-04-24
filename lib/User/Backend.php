<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\User;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\TokenValidatedEvent;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningService;
use OCA\UserOIDC\User\Validator\IBearerTokenValidator;
use OCA\UserOIDC\User\Validator\SelfEncodedValidator;
use OCA\UserOIDC\User\Validator\UserInfoValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\IApacheBackend;
use OCP\DB\Exception;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\ICustomLogout;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordConfirmationBackend;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class Backend extends ABackend implements IPasswordConfirmationBackend, IGetDisplayNameBackend, IApacheBackend, ICustomLogout, ICountUsersBackend {
	private const SESSION_USER_DATA = 'user_oidc.oidcUserData';

	/** @var list<class-string<IBearerTokenValidator>> */
	private $tokenValidators = [
		SelfEncodedValidator::class,
		UserInfoValidator::class,
	];

	public function __construct(
		private IConfig $config,
		private UserMapper $userMapper,
		private LoggerInterface $logger,
		private IRequest $request,
		private ISession $session,
		private IURLGenerator $urlGenerator,
		private IEventDispatcher $eventDispatcher,
		private DiscoveryService $discoveryService,
		private ProviderMapper $providerMapper,
		private ProviderService $providerService,
		private ProvisioningService $provisioningService,
		private LdapService $ldapService,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
	) {
	}

	public function getBackendName(): string {
		return Application::APP_ID;
	}

	/**
	 * Count the number of users managed by this OIDC backend.
	 *
	 * @return int the number of provisioned OIDC users
	 */
	public function countUsers(): int {
		return $this->userMapper->countUsers();
	}

	public function deleteUser($uid): bool {
		if (!is_string($uid) || $uid === '') {
			return false;
		}

		try {
			$user = $this->userMapper->getUser($uid);
			$this->userMapper->delete($user);
			return true;
		} catch (DoesNotExistException $e) {
			$this->logger->info('Tried to delete non-existent user', ['uid' => $uid, 'exception' => $e]);
			return false;
		} catch (Exception $e) {
			$this->logger->error('Failed to delete user', ['uid' => $uid, 'exception' => $e]);
			return false;
		}
	}

	public function getUsers($search = '', $limit = null, $offset = null): array {
		if (!is_string($search)
			|| ($limit !== null && !is_int($limit))
			|| ($offset !== null && !is_int($offset))
		) {
			return [];
		}
		return array_map(static fn ($user) => $user->getUserId(), $this->userMapper->find($search, $limit, $offset));
	}

	public function userExists($uid): bool {
		return is_string($uid) && $uid !== '' && $this->userMapper->userExists($uid);
	}

	public function getDisplayName($uid): string {
		if (!is_string($uid) || $uid === '') {
			return (string)$uid;
		}
		try {
			$user = $this->userMapper->getUser($uid);
			return $user->getDisplayName();
		} catch (DoesNotExistException) {
			return $uid;
		}
	}

	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		if (!is_string($search)
			|| ($limit !== null && !is_int($limit))
			|| ($offset !== null && !is_int($offset))
		) {
			return [];
		}

		return $this->userMapper->findDisplayNames($search, $limit, $offset);
	}

	public function hasUserListings(): bool {
		return true;
	}

	public function canConfirmPassword(string $uid): bool {
		return false;
	}

	/**
	 * As session cannot be injected in the constructor here, we inject it later
	 *
	 * @param ISession $session
	 * @return void
	 */
	public function injectSession(ISession $session): void {
		$this->session = $session;
	}

	/**
	 * In case the user has been authenticated by Apache true is returned.
	 *
	 * @return bool whether Apache reports a user as currently logged in.
	 * @since 6.0.0
	 */
	public function isSessionActive(): bool {
		// if this returns true, getCurrentUserId is called
		// not sure if we should rather to the validation in here as otherwise it might fail for other backends or bave other side effects
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		// session is active if we have a bearer token (API request) OR if we logged in via user_oidc (we have a provider ID in the session)
		return $headerToken !== '' || $this->session->get(LoginController::PROVIDERID) !== null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLogoutUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('user_oidc.login.singleLogoutService');
	}

	/**
	 * Return user data from the idp
	 * Inspired by user_saml
	 */
	public function getUserData(): array {
		$userData = $this->session->get(self::SESSION_USER_DATA) ?? [];
		$rawProviderId = $this->session->get(LoginController::PROVIDERID);
		if ($rawProviderId === null) {
			throw new \InvalidArgumentException('No OIDC provider ID in session');
		}

		$providerId = (int)$rawProviderId;

		$userData = $this->formatUserData($providerId, is_array($userData) ? $userData : []);

		if (!$this->isAcceptableUserId($userData['formatted']['uid'] ?? null)) {
			$uid = is_scalar($userData['formatted']['uid'] ?? null) ? (string)$userData['formatted']['uid'] : '';
			$this->logger->error('No valid uid given, please check your attribute mapping. Got uid: {uid}', [
				'app' => 'user_oidc',
				'uid' => $uid,
			]);
			throw new \InvalidArgumentException('No valid uid given, please check your attribute mapping. Got uid: ' . $uid);
		}

		return $userData;
	}

	/**
	 * Format user data and map them to the configured attributes
	 * Inspired by user_saml
	 */
	private function formatUserData(int $providerId, array $attributes): array {
		$result = ['formatted' => [], 'raw' => $attributes];

		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$result['formatted']['email'] = $this->provisioningService->getClaimValue($attributes, $emailAttribute, $providerId);

		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$result['formatted']['displayName'] = $this->provisioningService->getClaimValue($attributes, $displaynameAttribute, $providerId);
		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$result['formatted']['quota'] = $this->provisioningService->getClaimValue($attributes, $quotaAttribute, $providerId);
		if ($result['formatted']['quota'] === '') {
			$result['formatted']['quota'] = 'default';
		}

		$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
		$result['formatted']['groups'] = $this->provisioningService->getClaimValue($attributes, $groupsAttribute, $providerId);

		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');
		$result['formatted']['uid'] = $this->provisioningService->getClaimValue($attributes, $uidAttribute, $providerId);

		return $result;
	}

	/**
	 * Return the id of the current user
	 * @return string
	 * @since 6.0.0
	 */
	public function getCurrentUserId(): string {
		$oidcSystemConfig = $this->config->getSystemValue('user_oidc', []);
		$ncOidcProviderBearerValidation = isset($oidcSystemConfig['oidc_provider_bearer_validation']) && $oidcSystemConfig['oidc_provider_bearer_validation'] === true;

		$providers = $this->providerMapper->getProviders();
		if (count($providers) === 0 && !$ncOidcProviderBearerValidation) {
			$this->logger->debug('no OIDC providers and no NC provider validation');
			return '';
		}

		// get the bearer token from headers
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		if (!str_starts_with($headerToken, 'bearer ') && !str_starts_with($headerToken, 'Bearer ')) {
			$this->logger->debug('No Bearer token');
			return '';
		}
		$headerToken = preg_replace('/^bearer\s+/i', '', $headerToken);
		if ($headerToken === '') {
			$this->logger->debug('No Bearer token');
			return '';
		}

		// check if we should use UserInfoValidator (default is false)
		if (!isset($oidcSystemConfig['userinfo_bearer_validation']) || !$oidcSystemConfig['userinfo_bearer_validation']) {
			if (($key = array_search(UserInfoValidator::class, $this->tokenValidators)) !== false) {
				unset($this->tokenValidators[$key]);
			}
		}
		// check if we should use SelfEncodedValidator (default is true)
		if (isset($oidcSystemConfig['selfencoded_bearer_validation']) && !$oidcSystemConfig['selfencoded_bearer_validation']) {
			if (($key = array_search(SelfEncodedValidator::class, $this->tokenValidators)) !== false) {
				unset($this->tokenValidators[$key]);
			}
		}

		// check if we should ask the OIDC Identity Provider app (app_id: oidc) to validate the token (default is false)
		if ($ncOidcProviderBearerValidation) {
			if (class_exists(\OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent::class)) {
				try {
					$validationEvent = new \OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent($headerToken);
					$this->eventDispatcher->dispatchTyped($validationEvent);
					$oidcProviderUserId = $validationEvent->getUserId();
					if ($oidcProviderUserId !== null) {
						return $oidcProviderUserId;
					} else {
						$this->logger->debug('[NextcloudOidcProviderValidator] The bearer token validation has failed');
					}
				} catch (\Exception|\Throwable $e) {
					$this->logger->debug('[NextcloudOidcProviderValidator] The bearer token validation has crashed', ['exception' => $e]);
				}
			} else {
				$this->logger->debug('[NextcloudOidcProviderValidator] Impossible to validate bearer token with Nextcloud Oidc provider, OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent class not found');
			}
		} else {
			$this->logger->debug('[NextcloudOidcProviderValidator] oidc_provider_bearer_validation is false or not defined');
		}

		$autoProvisionAllowed = (!isset($oidcSystemConfig['auto_provision']) || $oidcSystemConfig['auto_provision']);

		// try to validate with all providers
		foreach ($providers as $provider) {
			if ($this->providerService->getSetting($provider->getId(), ProviderService::SETTING_CHECK_BEARER, '0') === '1') {
				// find user id through different token validation methods
				foreach ($this->tokenValidators as $validatorClass) {
					/** @var IBearerTokenValidator $validator */
					$validator = Server::get($validatorClass);
					try {
						$tokenUserId = $validator->isValidBearerToken($provider, $headerToken);
					} catch (Throwable|Exception $e) {
						$this->logger->debug('Failed to validate the bearer token', ['exception' => $e]);
						$tokenUserId = null;
					}
					if ($tokenUserId) {
						$this->logger->debug(
							'Token validated with ' . $validatorClass . ' by provider: ' . $provider->getId()
								. ' (' . $provider->getIdentifier() . ')'
						);
						// prevent login of users that are not in a whitelisted group (if activated)
						$restrictLoginToGroups = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS, '0');
						if ($restrictLoginToGroups === '1') {
							$tokenAttributes = $validator->getUserAttributes($provider, $headerToken);
							$syncGroups = $this->provisioningService->getSyncGroupsOfToken($provider->getId(), $tokenAttributes);

							if ($syncGroups === null || count($syncGroups) === 0) {
								$this->logger->debug('Prevented user from using a bearer token as user is not part of a whitelisted group');
								return '';
							}
						}
						$discovery = $this->discoveryService->obtainDiscovery($provider);
						$this->eventDispatcher->dispatchTyped(new TokenValidatedEvent(['token' => $headerToken], $provider, $discovery));

						if ($autoProvisionAllowed) {
							// look for user in other backends
							if (!$this->userManager->userExists($tokenUserId)) {
								$this->userManager->search($tokenUserId);
								$this->ldapService->syncUser($tokenUserId);
							}
							$existingUser = $this->userManager->get($tokenUserId);
							if ($existingUser !== null && $this->ldapService->isLdapDeletedUser($existingUser)) {
								$existingUser = null;
							}

							$softAutoProvisionAllowed = (!isset($oidcSystemConfig['soft_auto_provision']) || $oidcSystemConfig['soft_auto_provision']);
							if (!$softAutoProvisionAllowed && $existingUser !== null && $existingUser->getBackendClassName() !== Application::APP_ID) {
								// if soft auto-provisioning is disabled,
								// we refuse login for a user that already exists in another backend
								return '';
							}
							if ($existingUser === null) {
								// only create the user in our backend if the user does not exist in another backend
								$backendUser = $this->userMapper->getOrCreate($provider->getId(), $tokenUserId);
								$userId = $backendUser->getUserId();
							} else {
								$userId = $existingUser->getUID();
							}

							$this->checkFirstLogin($userId);

							if ($this->providerService->getSetting($provider->getId(), ProviderService::SETTING_BEARER_PROVISIONING, '0') === '1') {
								$provisioningStrategy = $validator->getProvisioningStrategy();
								if ($provisioningStrategy) {
									$this->provisionUser($validator->getProvisioningStrategy(), $provider, $tokenUserId, $headerToken, $existingUser);
								}
							}

							$this->session->set('last-password-confirm', $this->timeFactory->getTime() + 4 * 365 * 24 * 3600);
							$this->setSessionUser($userId);
							return $userId;
						} elseif ($this->userExists($tokenUserId)) {
							$this->checkFirstLogin($tokenUserId);
							$this->session->set('last-password-confirm', $this->timeFactory->getTime() + 4 * 365 * 24 * 3600);
							$this->setSessionUser($tokenUserId);
							return $tokenUserId;
						} else {
							// check if the user exists locally
							// if not, this potentially triggers a user_ldap search
							// to get the user if it has not been synced yet
							if (!$this->userManager->userExists($tokenUserId)) {
								$this->userManager->search($tokenUserId);
								$this->ldapService->syncUser($tokenUserId);

								// return nothing, if the user was not found after the user_ldap search
								if (!$this->userManager->userExists($tokenUserId)) {
									return '';
								}
							}

							$user = $this->userManager->get($tokenUserId);
							if ($user === null || $this->ldapService->isLdapDeletedUser($user)) {
								return '';
							}
							$this->checkFirstLogin($tokenUserId);
							$this->session->set('last-password-confirm', $this->timeFactory->getTime() + 4 * 365 * 24 * 3600);
							$this->setSessionUser($tokenUserId);
							return $tokenUserId;
						}
					}
				}
			}
		}

		$this->logger->debug('Could not find unique token validation');
		return '';
	}

	/**
	 * Returns true only if $userId is a non-empty, non-whitespace-only string.
	 * Used as a lightweight sanity check on user IDs returned by token validators
	 * before any database lookup or provisioning takes place.
	 */
	private function isAcceptableUserId(mixed $userId): bool {
		return is_string($userId) && $userId !== '' && trim($userId) !== '';
	}

	/**
	 * Set the user in IUserSession after bearer token validation.
	 * Without this, DI-injected $userId is null in OCS controllers
	 * and CalDAV plugins, causing 500 errors in Deck, Talk, and Tasks.
	 *
	 * Note: IUserSession is resolved via Server::get() rather than constructor
	 * injection to avoid a circular dependency (IUserSession depends on this Backend).
	 */
	private function setSessionUser(string $userId): void {
		try {
			$userSession = Server::get(IUserSession::class);
			$currentUser = $userSession->getUser();

			// Only fetch and set if the session doesn't already have this user
			if ($currentUser === null || $currentUser->getUID() !== $userId) {
				$user = $this->userManager->get($userId);
				if ($user !== null) {
					$userSession->setUser($user);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Failed to set session user after bearer validation: ' . $e->getMessage());
		}
	}

	/**
	 *
	 * Performs first-login initialisation (home folder setup, skeleton copy, events)
	 * if the user has never logged in before, then updates the last-login timestamp.
	 * Inspired by lib/private/User/Session.php::prepareUserLogin().
	 */
	private function checkFirstLogin(string $userId): bool {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return false;
		}

		$firstLogin = $user->getLastLogin() === 0;
		if ($firstLogin) {
			/** Replace with ServerVersion once we depend on NC 31 */
			if (version_compare($this->config->getSystemValueString('version', '0.0.0'), '34.0.0', '>=')) {
				Server::get(ISetupManager::class)->setupForUser($user);
			} else {
				\OC_Util::setupFS($userId);
			}

			try {
				// trigger creation of user home and /files folder
				$userFolder = Server::get(IRootFolder::class)->getUserFolder($userId);
				// copy skeleton
				\OC_Util::copySkeleton($userId, $userFolder);
			} catch (NotFoundException|NotPermittedException $ex) {
				$this->logger->warning('Could not set up user folder on first login', ['exception' => $ex]);
			}

			$this->eventDispatcher->dispatch(IUser::class . '::firstLogin', new GenericEvent($user));
			$this->eventDispatcher->dispatchTyped(new UserFirstTimeLoggedInEvent($user));
		}

		$user->updateLastLoginTimestamp();
		return $firstLogin;
	}

	/**
	 * Triggers user provisioning based on the provided strategy
	 */
	private function provisionUser(
		string $provisioningStrategyClass,
		Provider $provider,
		string $tokenUserId,
		string $headerToken,
		?IUser $existingUser,
	): ?IUser {
		try {
			$provisioningStrategy = Server::get($provisioningStrategyClass);
			return $provisioningStrategy->provisionUser($provider, $tokenUserId, $headerToken, $existingUser);
		} catch (Throwable $e) {
			$this->logger->error('Failed to provision user via strategy', [
				'strategy' => $provisioningStrategyClass,
				'providerId' => $provider->getId(),
				'providerIdentifier' => $provider->getIdentifier(),
				'userId' => $tokenUserId,
				'exception' => $e,
			]);
			return null;
		}
	}
}
