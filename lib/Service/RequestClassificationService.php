<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCP\IRequest;

class RequestClassificationService {
	public static function isTopLevelHtmlNavigation(IRequest $request): bool {
		if (strtoupper($request->getMethod()) !== 'GET') {
			return false;
		}

		if ($request->getHeader('OCS-apirequest') !== '') {
			return false;
		}

		if ($request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
			return false;
		}

		return true;
	}
}
