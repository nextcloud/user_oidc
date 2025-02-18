<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Cron;

use DateTime;
use OCA\UserOIDC\Db\SessionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class CleanupSessions extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private IConfig $config,
		private SessionMapper $sessionMapper,
	) {
		parent::__construct($time);
		// daily
		$this->setInterval(24 * 60 * 60);
		if (method_exists($this, 'setTimeSensitivity')) {
			$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		}
	}

	/**
	 * @param $argument
	 * @return void
	 */
	protected function run($argument): void {
		$nowTimestamp = (new DateTime())->getTimestamp();
		$configSessionLifetime = $this->config->getSystemValueInt('session_lifetime', 60 * 60 * 24);
		$configCookieLifetime = $this->config->getSystemValueInt('remember_login_cookie_lifetime', 60 * 60 * 24 * 15);
		$since = $nowTimestamp - max($configSessionLifetime, $configCookieLifetime);
		$this->sessionMapper->cleanupSessions($since);
	}
}
