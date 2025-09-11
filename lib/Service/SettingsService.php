<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCP\IAppConfig;

class SettingsService {

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getAllowMultipleUserBackEnds(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'allow_multiple_user_backends', '1') === '1';
	}

	public function setAllowMultipleUserBackEnds(bool $value): void {
		$this->appConfig->setValueString(Application::APP_ID, 'allow_multiple_user_backends', $value ? '1' : '0');
	}
}
