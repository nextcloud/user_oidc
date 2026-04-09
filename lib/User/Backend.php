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
	private const SESSION_PASSWORD_CONFIRM = 'last-password-confirm';
	private const PASSWORD_CONFIRM_TTL = 126230400; // 4 years

	/** @var list<class-string<IBearerTokenValidator>> */
	private array $tokenValidators = [
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
	 */
	public function injectSession(ISession $session): void {
		$this->session = $session;
	}

	/**
	 * In case the user has been authenticated by Apache true is returned.
	 *
	 * Note: prior to this refactor, any non-empty OIDC header value (including
	 * malformed ones without a "Bearer " prefix) was enough to return true.
	 * Now only well-formed Bearer tokens are considered, which avoids calling
	 * getCurrentUserId() for requests that could never authenticate anyway.
	 *
	 * @return bool whether Apache reports a user as currently logged in.
	 * @since 6.0.0
	 */
	public function isSessionActive(): bool {
		return $this->extractBearerToken() !== null
			|| $this->session->get(LoginController::PROVIDERID) !== null;
	}

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
	 * @since 6.0.0
	 */
	public function getCurrentUserId(): string {
		$oidcSystemConfig = $this->getOidcSystemConfig();
		$headerToken = $this->extractBearerToken();

		if ($headerToken === null) {
			$this->logger->debug('No Bearer token');
			return '';
		}

		$ncOidcProviderBearerValidation = ($oidcSystemConfig['oidc_provider_bearer_validation'] ?? false) === true;
		$providers = $this->providerMapper->getProviders();

		if (count($providers) === 0 && !$ncOidcProviderBearerValidation) {
			$this->logger->debug('No OIDC providers and no NC provider validation');
			return '';
		}

		if ($ncOidcProviderBearerValidation) {
			$nextcloudProviderUserId = $this->validateWithNextcloudProvider($headerToken);
			if ($nextcloudProviderUserId !== null) {
				$user = $this->findExistingAccountByUid($nextcloudProviderUserId, true);
				if ($user === null) {
					$this->logger->debug('[NextcloudOidcProviderValidator] Valid token for unknown user', [
						'userId' => $nextcloudProviderUserId,
					]);
					return '';
				}

				$this->checkFirstLogin($nextcloudProviderUserId);
				return $this->finalizeAuthenticatedUser($nextcloudProviderUserId);
			}
		} else {
			$this->logger->debug('[NextcloudOidcProviderValidator] oidc_provider_bearer_validation is false or not defined');
		}

		$validators = $this->getActiveTokenValidators($oidcSystemConfig);
		if (count($validators) === 0) {
			$this->logger->debug('No active bearer token validators');
			return '';
		}

		$match = $this->findUniqueTokenMatch($providers, $validators, $headerToken);
		if ($match === null) {
			return '';
		}

		$provider = $match['provider'];
		$validator = $match['validator'];
		$tokenUserId = $match['userId'];

		$discovery = $this->discoveryService->obtainDiscovery($provider);
		$this->eventDispatcher->dispatchTyped(new TokenValidatedEvent(['token' => $headerToken], $provider, $discovery));

		return $this->resolveAuthenticatedUser($provider, $validator, $tokenUserId, $headerToken, $oidcSystemConfig);
	}

	/**
	 * Extracts the Bearer token from the Authorization header.
	 * Returns null if the header is absent, malformed, or contains an empty token.
	 */
	private function extractBearerToken(): ?string {
		$header = trim($this->request->getHeader(Application::OIDC_API_REQ_HEADER));
		if ($header === '') {
			return null;
		}

		if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
			return null;
		}

		$token = trim($matches[1]);
		return $token !== '' ? $token : null;
	}

	/**
	 * Returns the user_oidc system config array, or an empty array if unset or invalid.
	 */
	private function getOidcSystemConfig(): array {
		$config = $this->config->getSystemValue('user_oidc', []);
		return is_array($config) ? $config : [];
	}

	/**
	 * Returns the list of instantiated token validators that are enabled by config.
	 * By default only SelfEncodedValidator is active. UserInfoValidator must be
	 * explicitly enabled via userinfo_bearer_validation, and SelfEncodedValidator
	 * can be disabled via selfencoded_bearer_validation.
	 *
	 * @return list<IBearerTokenValidator>
	 */
	private function getActiveTokenValidators(array $oidcSystemConfig): array {
		$activeValidators = $this->tokenValidators;

		if (($oidcSystemConfig['userinfo_bearer_validation'] ?? false) !== true) {
			$activeValidators = array_values(array_filter(
				$activeValidators,
				static fn (string $validatorClass): bool => $validatorClass !== UserInfoValidator::class
			));
		}

		if (($oidcSystemConfig['selfencoded_bearer_validation'] ?? true) !== true) {
			$activeValidators = array_values(array_filter(
				$activeValidators,
				static fn (string $validatorClass): bool => $validatorClass !== SelfEncodedValidator::class
			));
		}

		$validators = [];
		foreach ($activeValidators as $validatorClass) {
			try {
				$validator = Server::get($validatorClass);
				if ($validator instanceof IBearerTokenValidator) {
					$validators[] = $validator;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Failed to instantiate bearer token validator', [
					'class' => $validatorClass,
					'exception' => $e,
				]);
			}
		}

		return $validators;
	}

	/**
	 * Attempts to validate the Bearer token via a TokenValidationRequestEvent.
	 * This path is only active when oidc_provider_bearer_validation is true in config.php.
	 *
	 * Unlike the provider-loop path, this validation is not tied to any user_oidc provider
	 * entry: it delegates entirely to the oidc app.
	 *
	 * Returns the validated user ID, or null if the token is invalid or the app is absent.
	 */
	private function validateWithNextcloudProvider(string $headerToken): ?string {
		if (!class_exists(\OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent::class)) {
			$this->logger->debug('[NextcloudOidcProviderValidator] Impossible to validate bearer token with Nextcloud Oidc provider, class not found');
			return null;
		}

		try {
			$validationEvent = new \OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent($headerToken);
			$this->eventDispatcher->dispatchTyped($validationEvent);
			$userId = $validationEvent->getUserId();

			if ($this->isAcceptableUserId($userId)) {
				return $userId;
			}

			$this->logger->debug('[NextcloudOidcProviderValidator] The bearer token validation has failed');
			return null;
		} catch (Throwable $e) {
			$this->logger->debug('[NextcloudOidcProviderValidator] The bearer token validation has crashed', [
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Tries every combination of configured providers and active validators against
	 * the Bearer token. Returns the single matching (provider, validator, userId) tuple,
	 * or null if there is no match or if more than one distinct (provider, userId) pair
	 * validates successfully (ambiguous token). Ambiguity is logged as a warning and
	 * treated as an authentication failure to avoid privilege confusion.
	 *
	 * @param list<Provider> $providers
	 * @param list<IBearerTokenValidator> $validators
	 * @return array{provider: Provider, validator: IBearerTokenValidator, userId: string}|null
	 */
	private function findUniqueTokenMatch(array $providers, array $validators, string $headerToken): ?array {
		$matches = [];

		foreach ($providers as $provider) {
			if ($this->providerService->getSetting($provider->getId(), ProviderService::SETTING_CHECK_BEARER, '0') !== '1') {
				continue;
			}

			foreach ($validators as $validator) {
				try {
					$tokenUserId = $validator->isValidBearerToken($provider, $headerToken);
				} catch (Throwable $e) {
					$this->logger->debug('Failed to validate the bearer token', [
						'providerId' => $provider->getId(),
						'providerIdentifier' => $provider->getIdentifier(),
						'validator' => $validator::class,
						'exception' => $e,
					]);
					continue;
				}

				if (!is_string($tokenUserId) || !$this->isAcceptableUserId($tokenUserId)) {
					continue;
				}

				if (!$this->isBearerLoginAllowedForProvider($provider, $validator, $headerToken)) {
					continue;
				}

				$matchKey = $provider->getId() . "\n" . $tokenUserId;

				if (!isset($matches[$matchKey])) {
					$matches[$matchKey] = [
						'provider' => $provider,
						'validator' => $validator,
						'userId' => $tokenUserId,
					];

					if (count($matches) > 1) {
						$this->logger->warning('Bearer token validation is ambiguous across providers or user IDs', [
							'matches' => array_map(
								static fn (array $match): array => [
									'providerId' => $match['provider']->getId(),
									'providerIdentifier' => $match['provider']->getIdentifier(),
									'validator' => $match['validator']::class,
									'userId' => $match['userId'],
								],
								array_values($matches)
							),
						]);
						return null;
					}
				}
			}
		}

		if (count($matches) === 0) {
			$this->logger->debug('Could not validate bearer token with any configured provider');
			return null;
		}

		$match = array_values($matches)[0];
		$this->logger->debug(
			'Token validated with ' . $match['validator']::class . ' by provider:' . $match['provider']->getId()
			. ' (' . $match['provider']->getIdentifier() . ')'
		);

		return $match;
	}

	/**
	 * Checks whether the bearer-token login is permitted for the given provider.
	 * If the provider has SETTING_RESTRICT_LOGIN_TO_GROUPS enabled, the token must
	 * carry at least one of the whitelisted groups; otherwise login is denied.
	 */
	private function isBearerLoginAllowedForProvider(
		Provider $provider,
		IBearerTokenValidator $validator,
		string $headerToken,
	): bool {
		$restrictLoginToGroups = $this->providerService->getSetting(
			$provider->getId(),
			ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS,
			'0'
		);

		if ($restrictLoginToGroups !== '1') {
			return true;
		}

		try {
			$tokenAttributes = $validator->getUserAttributes($provider, $headerToken);
			$syncGroups = $this->provisioningService->getSyncGroupsOfToken($provider->getId(), $tokenAttributes);
		} catch (Throwable $e) {
			$this->logger->debug('Failed to read token attributes for group restriction', [
				'providerId' => $provider->getId(),
				'providerIdentifier' => $provider->getIdentifier(),
				'validator' => $validator::class,
				'exception' => $e,
			]);
			return false;
		}

		if (empty($syncGroups)) {
			$this->logger->debug('Prevented user from using a bearer token as user is not part of a whitelisted group', [
				'providerId' => $provider->getId(),
				'providerIdentifier' => $provider->getIdentifier(),
			]);
			return false;
		}

		return true;
	}

	/**
	 * Resolves the final Nextcloud user ID for a validated Bearer token and completes
	 * the session setup. This is the central provisioning decision point:
	 *
	 * - auto_provision = true (default): the user is created in this backend if it does
	 *   not exist in any backend. If soft_auto_provision = false, login is refused for
	 *   users that already exist in a different backend.
	 * - auto_provision = false: only users that already exist (in any backend) are
	 *   allowed; no account is created.
	 *
	 * In both cases, bearer_provisioning (if enabled) is triggered to sync attributes
	 * (display name, quota, groups) from the token before the user ID is returned.
	 */
	private function resolveAuthenticatedUser(
		Provider $provider,
		IBearerTokenValidator $validator,
		string $tokenUserId,
		string $headerToken,
		array $oidcSystemConfig,
	): string {
		$autoProvisionAllowed = ($oidcSystemConfig['auto_provision'] ?? true) === true;
		$softAutoProvisionAllowed = ($oidcSystemConfig['soft_auto_provision'] ?? true) === true;

		$existingUser = $this->findExistingAccountByUid($tokenUserId, true);

		if ($autoProvisionAllowed) {
			if (!$softAutoProvisionAllowed
				&& $existingUser !== null
				&& $existingUser->getBackendClassName() !== Application::APP_ID
			) {
				return '';
			}

			if ($existingUser === null) {
				$backendUser = $this->userMapper->getOrCreate($provider->getId(), $tokenUserId);
				$userId = $backendUser->getUserId();
				// $existingUser intentionally left null: provisionUser receives null for
				// newly created accounts, matching the pre-refactor behaviour. Passing the
				// freshly created IUser here would be wrong when SETTING_UNIQUE_UID or
				// SETTING_PROVIDER_BASED_ID are enabled, because the stored user ID may be
				// a hash or a prefixed value — different from $tokenUserId — and the
				// provisioning strategy would then act on the wrong identity.
			} else {
				$userId = $existingUser->getUID();
			}

			$this->checkFirstLogin($userId);

			if ($this->providerService->getSetting($provider->getId(), ProviderService::SETTING_BEARER_PROVISIONING, '0') === '1') {
				$provisioningStrategy = $validator->getProvisioningStrategy();
				if ($provisioningStrategy !== '') {
					$this->provisionUser($provisioningStrategy, $provider, $tokenUserId, $headerToken, $existingUser);
				}
			}

			return $this->finalizeAuthenticatedUser($userId);
		}

		if ($existingUser === null) {
			return '';
		}

		$userId = $existingUser->getUID();
		$this->checkFirstLogin($userId);
		return $this->finalizeAuthenticatedUser($userId);
	}

	/**
	 * Looks up an existing Nextcloud user by ID across all backends.
	 * When $allowLdapSync is true and the user is not found, a user_ldap search and
	 * sync are triggered so that LDAP users are pulled in before we conclude the
	 * account does not exist. Returns null if the user is not found or is a
	 * soft-deleted LDAP user.
	 */
	private function findExistingAccountByUid(string $userId, bool $allowLdapSync): ?IUser {
		$user = $this->userManager->get($userId);

		if ($user === null && $allowLdapSync) {
			$this->userManager->search($userId);
			$this->ldapService->syncUser($userId);
			$user = $this->userManager->get($userId);
		}

		if ($user !== null && $this->ldapService->isLdapDeletedUser($user)) {
			return null;
		}

		return $user;
	}

	/**
	 * Completes authentication by stamping the session with a long-lived
	 * password-confirmation timestamp (preventing re-auth prompts for OIDC users)
	 * and setting the active user on IUserSession.
	 */
	private function finalizeAuthenticatedUser(string $userId): string {
		$this->session->set(self::SESSION_PASSWORD_CONFIRM, $this->timeFactory->getTime() + self::PASSWORD_CONFIRM_TTL);
		$this->setSessionUser($userId);
		return $userId;
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

			if ($currentUser !== null && $currentUser->getUID() === $userId) {
				return;
			}

			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$userSession->setUser($user);
			}
		} catch (Throwable $e) {
			$this->logger->debug('Failed to set session user after bearer validation', [
				'userId' => $userId,
				'exception' => $e,
			]);
		}
	}

	/**
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
			/** @psalm-suppress UndefinedVariable Replace with ServerVersion once we depend on NC 31 */
			if ($OC_Version[0] >= 34) {
				Server::get(ISetupManager::class)->setupForUser($user);
			} else {
				\OC_Util::setupFS($userId);
			}

			try {
				$userFolder = Server::get(IRootFolder::class)->getUserFolder($userId);
				\OC_Util::copySkeleton($userId, $userFolder);
			} catch (NotFoundException|NotPermittedException $e) {
				$this->logger->warning('Could not set up user folder on first login', ['exception' => $e]);
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
