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
use OCA\UserOIDC\User\Validator\SelfEncodedValidator;
use OCA\UserOIDC\User\Validator\UserInfoValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\IApacheBackend;
use OCP\DB\Exception;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\ICustomLogout;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordConfirmationBackend;
use Psr\Log\LoggerInterface;

class Backend extends ABackend implements IPasswordConfirmationBackend, IGetDisplayNameBackend, IApacheBackend, ICustomLogout, ICountUsersBackend {
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
	) {
	}

	public function getBackendName(): string {
		return Application::APP_ID;
	}

	public function countUsers(): int {
		$uids = $this->getUsers();
		return count($uids);
	}

	public function deleteUser($uid): bool {
		try {
			$user = $this->userMapper->getUser($uid);
			$this->userMapper->delete($user);
			return true;
		} catch (Exception $e) {
			$this->logger->error('Failed to delete user', [ 'exception' => $e ]);
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
		return array_map(function ($user) {
			return $user->getUserId();
		}, $this->userMapper->find($search, $limit, $offset));
	}

	public function userExists($uid): bool {
		if (!is_string($uid)) {
			return false;
		}
		return $this->userMapper->userExists($uid);
	}

	public function getDisplayName($uid): string {
		if (!is_string($uid)) {
			return (string)$uid;
		}
		try {
			$user = $this->userMapper->getUser($uid);
		} catch (DoesNotExistException $e) {
			return $uid;
		}

		return $user->getDisplayName();
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
		return $this->urlGenerator->linkToRouteAbsolute(
			'user_oidc.login.singleLogoutService',
			[
				'requesttoken' => \OC::$server->getCsrfTokenManager()->getToken()->getEncryptedValue(),
			]
		);
	}

	/**
	 * Return user data from the idp
	 * Inspired by user_saml
	 */
	public function getUserData(): array {
		$userData = $this->session->get('user_oidc.oidcUserData');
		$providerId = (int)$this->session->get(LoginController::PROVIDERID);
		$userData = $this->formatUserData($providerId, $userData);

		// make sure that a valid UID is given
		if (empty($userData['formatted']['uid'])) {
			$this->logger->error('No valid uid given, please check your attribute mapping. Got uid: {uid}', ['app' => 'user_oidc', 'uid' => $userData['formatted']['uid']]);
			throw new \InvalidArgumentException('No valid uid given, please check your attribute mapping. Got uid: ' . $userData['formatted']['uid']);
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
					$validator = \OC::$server->get($validatorClass);
					$tokenUserId = $validator->isValidBearerToken($provider, $headerToken);
					if ($tokenUserId) {
						$this->logger->debug(
							'Token validated with ' . $validatorClass . ' by provider: ' . $provider->getId()
								. ' (' . $provider->getIdentifier() . ')'
						);
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

							$this->session->set('last-password-confirm', strtotime('+4 year', time()));
							return $userId;
						} elseif ($this->userExists($tokenUserId)) {
							$this->checkFirstLogin($tokenUserId);
							$this->session->set('last-password-confirm', strtotime('+4 year', time()));
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
							$this->session->set('last-password-confirm', strtotime('+4 year', time()));
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
	 * Inspired by lib/private/User/Session.php::prepareUserLogin()
	 *
	 * @param string $userId
	 * @return bool
	 * @throws NotFoundException
	 */
	private function checkFirstLogin(string $userId): bool {
		$user = $this->userManager->get($userId);

		if ($user === null) {
			return false;
		}

		$firstLogin = $user->getLastLogin() === 0;
		if ($firstLogin) {
			\OC_Util::setupFS($userId);
			// trigger creation of user home and /files folder
			$userFolder = \OC::$server->getUserFolder($userId);
			try {
				// copy skeleton
				\OC_Util::copySkeleton($userId, $userFolder);
			} catch (NotPermittedException $ex) {
				// read only uses
			}

			// trigger any other initialization
			$this->eventDispatcher->dispatch(IUser::class . '::firstLogin', new GenericEvent($user));
			// TODO add this when user_oidc min NC version is >= 28
			// $this->eventDispatcher->dispatchTyped(new UserFirstTimeLoggedInEvent($user));
		}
		$user->updateLastLoginTimestamp();
		return $firstLogin;
	}

	/**
	 * Triggers user provisioning based on the provided strategy
	 *
	 * @param string $provisioningStrategyClass
	 * @param Provider $provider
	 * @param string $tokenUserId
	 * @param string $headerToken
	 * @param IUser|null $existingUser
	 * @return IUser|null
	 */
	private function provisionUser(
		string $provisioningStrategyClass, Provider $provider, string $tokenUserId, string $headerToken,
		?IUser $existingUser,
	): ?IUser {
		$provisioningStrategy = \OC::$server->get($provisioningStrategyClass);
		return $provisioningStrategy->provisionUser($provider, $tokenUserId, $headerToken, $existingUser);
	}
}
