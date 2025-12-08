<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;

class TimezoneController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ISession $session,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param string $timezone
	 * @param int $timezoneOffset
	 * @return JSONResponse
	 * @throws \OCP\PreConditionNotMetException
	 */
	#[NoAdminRequired]
	#[UseSession]
	public function setTimezone(string $timezone, int $timezoneOffset) {
		$this->config->setUserValue($this->userId, 'core', 'timezone', $timezone);
		$this->session->set('timezone', $timezoneOffset);

		return new JSONResponse();
	}
}
