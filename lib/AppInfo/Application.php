<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\AppInfo;

use Exception;
use OC_App;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Event\ExchangedTokenRequestedEvent;
use OCA\UserOIDC\Event\ExternalTokenRequestedEvent;
use OCA\UserOIDC\Event\InternalTokenRequestedEvent;
use OCA\UserOIDC\Listener\ExchangedTokenRequestedListener;
use OCA\UserOIDC\Listener\ExternalTokenRequestedListener;
use OCA\UserOIDC\Listener\InternalTokenRequestedListener;
use OCA\UserOIDC\Listener\TimezoneHandlingListener;
use OCA\UserOIDC\Listener\TokenInvalidatedListener;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\SettingsService;
use OCA\UserOIDC\Service\TokenService;
use OCA\UserOIDC\User\Backend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Throwable;

class Application extends App implements IBootstrap {
	public const APP_ID = 'junovy_user_oidc';
	public const OIDC_API_REQ_HEADER = 'Authorization';

	private $backend;
	private $cachedProviders;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		/** @var IUserManager $userManager */
		$userManager = $this->getContainer()->get(IUserManager::class);

		/* Register our own user backend */
		$this->backend = $this->getContainer()->get(Backend::class);

		$config = $this->getContainer()->get(IConfig::class);
		if (version_compare($config->getSystemValueString('version', '0.0.0'), '32.0.0', '>=')) {
			// see https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/app_upgrade_guide/upgrade_to_32.html#id3
			$userManager->registerBackend($this->backend);
		} else {
			\OC_User::useBackend($this->backend);
		}

		$context->registerEventListener(LoadAdditionalScriptsEvent::class, TimezoneHandlingListener::class);
		$context->registerEventListener(ExchangedTokenRequestedEvent::class, ExchangedTokenRequestedListener::class);
		$context->registerEventListener(ExternalTokenRequestedEvent::class, ExternalTokenRequestedListener::class);
		$context->registerEventListener(InternalTokenRequestedEvent::class, InternalTokenRequestedListener::class);

		if (class_exists(\OCP\Authentication\Events\TokenInvalidatedEvent::class)) {
			$context->registerEventListener(\OCP\Authentication\Events\TokenInvalidatedEvent::class, TokenInvalidatedListener::class);
		}
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(\Closure::fromCallable([$this->backend, 'injectSession']));
		$context->injectFn(\Closure::fromCallable([$this, 'checkLoginToken']));
		/** @var IUserSession $userSession */
		$userSession = $this->getContainer()->get(IUserSession::class);
		if ($userSession->isLoggedIn()) {
			return;
		}

		try {
			$context->injectFn(\Closure::fromCallable([$this, 'registerRedirect']));
			$context->injectFn(\Closure::fromCallable([$this, 'registerLogin']));
		} catch (Throwable $e) {
		}
	}

	private function checkLoginToken(TokenService $tokenService): void {
		$tokenService->checkLoginToken();
	}

	private function registerRedirect(IRequest $request, IURLGenerator $urlGenerator, SettingsService $settings, ProviderMapper $providerMapper, ProviderService $providerService, IConfig $config): void {
		$providers = $this->getCachedProviders($providerMapper);
		$redirectUrl = $request->getParam('redirect_url');
		$absoluteRedirectUrl = !empty($redirectUrl) ? $urlGenerator->getAbsoluteURL($redirectUrl) : $redirectUrl;

		// Handle immediate redirect to the oidc provider if just one is configured and no other backends are allowed
		$isDefaultLogin = false;
		try {
			$isDefaultLogin = $request->getPathInfo() === '/login' && $request->getParam('direct') !== '1';
		} catch (Exception $e) {
			// in case any errors happen when checking for the path do not apply redirect logic as it is only needed for the login
		}

		if ($isDefaultLogin && count($providers) === 1) {
			$provider = $providers[0];
			// Check per-provider auto_redirect setting, then global config, then default behavior
			$autoRedirect = $providerService->getConfigValue(
				$provider->getId(),
				ProviderService::SETTING_AUTO_REDIRECT,
				false
			);

			// If auto_redirect is enabled for this provider, or if multiple backends are not allowed (legacy behavior)
			if ($autoRedirect || (!$settings->getAllowMultipleUserBackEnds() && $autoRedirect !== false)) {
				$targetUrl = $urlGenerator->linkToRoute(self::APP_ID . '.login.login', [
					'providerId' => $provider->getId(),
					'redirectUrl' => $absoluteRedirectUrl
				]);
				header('Location: ' . $targetUrl);
				exit();
			}
		}
	}

	private function registerLogin(
		IRequest $request, IL10N $l10n, IURLGenerator $urlGenerator, IConfig $config, ProviderMapper $providerMapper, ProviderService $providerService,
	): void {
		$redirectUrl = $request->getParam('redirect_url');
		$absoluteRedirectUrl = !empty($redirectUrl) ? $urlGenerator->getAbsoluteURL($redirectUrl) : $redirectUrl;
		$providers = $this->getCachedProviders($providerMapper);
		$customLoginLabel = $config->getSystemValue('junovy_user_oidc', [])['login_label'] ?? '';
		foreach ($providers as $provider) {
			// Get per-provider button text, fallback to global config, then default
			$buttonText = $providerService->getConfigValue(
				$provider->getId(),
				ProviderService::SETTING_BUTTON_TEXT,
				$customLoginLabel
			);

			$loginName = $buttonText
				? preg_replace('/{name}/', $provider->getIdentifier(), $buttonText)
				: $l10n->t('Login with %1s', [$provider->getIdentifier()]);

			// FIXME: Move to IAlternativeLogin but requires boot due to db connection
			OC_App::registerLogIn([
				'name' => $loginName,
				'href' => $urlGenerator->linkToRoute(self::APP_ID . '.login.login', ['providerId' => $provider->getId(), 'redirectUrl' => $absoluteRedirectUrl]),
			]);
		}

		/** @var ID4MeService $id4meService */
		$id4meService = $this->getContainer()->get(ID4MeService::class);
		if ($id4meService->getID4ME()) {
			OC_App::registerLogIn([
				'name' => 'ID4ME',
				'href' => $urlGenerator->linkToRoute(self::APP_ID . '.id4me.login'),
			]);
		}
	}

	private function getCachedProviders(ProviderMapper $providerMapper): array {
		if (!isset($this->cachedProviders)) {
			$this->cachedProviders = $providerMapper->getProviders();
		}

		return $this->cachedProviders;
	}
}
