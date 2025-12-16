<?php

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);


namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class SettingsService {

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function getAllowMultipleUserBackEnds(): bool {
		try {
			return $this->appConfig->getValueString(Application::APP_ID, 'allow_multiple_user_backends', '1', lazy: true) === '1';
		} catch (AppConfigTypeConflictException $e) {
			$this->logger->warning('Incorrect app config type when getting "allow_multiple_user_backends"', ['exception' => $e]);
			return true;
		}
	}

	public function setAllowMultipleUserBackEnds(bool $value): void {
		try {
			$this->appConfig->setValueString(Application::APP_ID, 'allow_multiple_user_backends', $value ? '1' : '0', lazy: true);
		} catch (AppConfigTypeConflictException $e) {
			$this->logger->warning('Incorrect app config type when setting "allow_multiple_user_backends"', ['exception' => $e]);
			$this->appConfig->deleteKey(Application::APP_ID, 'allow_multiple_user_backends');
			$this->appConfig->setValueString(Application::APP_ID, 'allow_multiple_user_backends', $value ? '1' : '0', lazy: true);
		}
	}
}
