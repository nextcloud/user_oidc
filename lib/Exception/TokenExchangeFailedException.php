<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Exception;

use Exception;

class TokenExchangeFailedException extends Exception {

	public function __construct(
		$message = '',
		$code = 0,
		$previous = null,
		private ?string $error = null,
		private ?string $errorDescription = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getError(): ?string {
		return $this->error;
	}

	public function getErrorDescription(): ?string {
		return $this->errorDescription;
	}
}
