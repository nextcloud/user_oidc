<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCP\IConfig;

class ID4MeService {

	public function __construct(
		private IConfig $config,
	) {
	}

	public function setID4ME(bool $enabled): void {
		$this->config->setAppValue(Application::APP_ID, 'id4me_enabled', $enabled ? '1' : '0');
	}

	public function getID4ME(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'id4me_enabled', '0') === '1';
	}
}
