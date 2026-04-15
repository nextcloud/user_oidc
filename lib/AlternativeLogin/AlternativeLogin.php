<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\AlternativeLogin;

use OCP\Authentication\IAlternativeLogin;

class AlternativeLogin implements IAlternativeLogin {
	public function __construct(
		private string $name,
		private string $href,
	) {
	}

	public function getLabel(): string {
		return $this->name;
	}

	public function getLink(): string {
		return $this->href;
	}

	public function getClass(): string {
		return '';
	}

	public function load(): void {
	}
}
