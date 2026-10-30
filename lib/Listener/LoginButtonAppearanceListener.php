<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Listener;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @implements IEventListener<BeforeLoginTemplateRenderedEvent|Event>
 */
class LoginButtonAppearanceListener implements IEventListener {

	public function __construct(
		private ProviderMapper $providerMapper,
		private ProviderService $providerService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeLoginTemplateRenderedEvent) {
			return;
		}

		$css = '';
		$hasIcon = false;
		foreach ($this->providerMapper->getProviders() as $provider) {
			$selector = '#alternative-logins a.oidc-provider-' . $provider->getId();

			$icon = $this->providerService->getAppearanceIcon($provider->getId());
			if ($icon !== null) {
				$hasIcon = true;
				$css .= $selector . '::before { background-image: url(' . $icon . '); }' . "\n";
			}

			$color = $this->providerService->getAppearanceButtonBackgroundColor($provider->getId());
			if ($color !== null) {
				$css .= $selector . ' { border: 0; color: #fff !important; background-color: ' . $color . ' !important; }' . "\n";
			}
		}

		if ($hasIcon) {
			Util::addStyle(Application::APP_ID, 'oidc_button');
		}
		if ($css !== '') {
			Util::addHeader('style', [], $css);
		}
	}
}
