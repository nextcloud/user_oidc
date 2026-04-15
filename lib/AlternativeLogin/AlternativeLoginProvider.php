<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\AlternativeLogin;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ID4MeService;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * @psalm-suppress UndefinedClass
 */
class AlternativeLoginProvider implements IAlternativeLoginProvider {
	public function __construct(
		private IRequest $request,
		private IUrlGenerator $urlGenerator,
		private ProviderMapper $providerMapper,
		private IConfig $config,
		private IL10N $l10n,
		private ID4MeService $id4MeService,
	) {
	}

	public function getAlternativeLogins(): array {
		$alternativeLogins = [];
		$redirectUrl = $this->request->getParam('redirect_url');
		$absoluteRedirectUrl = !empty($redirectUrl) ? $this->urlGenerator->getAbsoluteURL($redirectUrl) : $redirectUrl;
		$providers = $this->providerMapper->getProviders();
		$customLoginLabel = $this->config->getSystemValue('user_oidc', [])['login_label'] ?? '';
		foreach ($providers as $provider) {
			$alternativeLogins[] = new AlternativeLogin(
				$customLoginLabel
					? preg_replace('/{name}/', $provider->getIdentifier(), $customLoginLabel)
					: $this->l10n->t('Login with %1s', [$provider->getIdentifier()]),
				$this->urlGenerator->linkToRoute(Application::APP_ID . '.login.login', ['providerId' => $provider->getId(), 'redirectUrl' => $absoluteRedirectUrl]),
			);
		}

		if ($this->id4MeService->getID4ME()) {
			$alternativeLogins[] = new AlternativeLogin(
				'ID4ME',
				$this->urlGenerator->linkToRoute(Application::APP_ID . '.id4me.login'),
			);
		}

		return $alternativeLogins;
	}
}
