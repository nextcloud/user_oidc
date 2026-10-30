<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\AlternativeLogin;

use OCP\Authentication\IAlternativeLogin;
use OCP\IL10N;
use OCP\Util;

class DefaultLoginShow implements IAlternativeLogin {
	public function __construct(
		private string $appName,
		private IL10N $l,
	) {
	}

	public function getLabel(): string {
		return $this->l->t('Log in with username or email');
	}

	public function getLink(): string {
		return '#body-login';
	}

	public function getClass(): string {
		return '';
	}

	public function load(): void {
		Util::addStyle('user_oidc', 'hide_default_login');
	}
}
