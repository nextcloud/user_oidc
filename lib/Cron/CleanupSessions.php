<?php
/**
 * @copyright Copyright (c) 2022, Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Cron;

use DateTime;
use OCA\UserOIDC\Db\SessionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class CleanupSessions extends TimedJob {

	/**
	 * @var SessionMapper
	 */
	private $sessionMapper;
	/**
	 * @var IConfig
	 */
	private $config;

	public function __construct(ITimeFactory  $time,
								IConfig $config,
								SessionMapper $sessionMapper) {
		parent::__construct($time);
		$this->sessionMapper = $sessionMapper;
		$this->config = $config;
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
