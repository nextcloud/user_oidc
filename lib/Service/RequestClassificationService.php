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

		$accept = strtolower($request->getHeader('Accept'));
		if ($accept !== '' && strpos($accept, 'text/html') === false) {
			return false;
		}

		if ($request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
			return false;
		}

		$secFetchMode = strtolower($request->getHeader('Sec-Fetch-Mode'));
		if ($secFetchMode !== '' && $secFetchMode !== 'navigate') {
			return false;
		}

		$secFetchDest = strtolower($request->getHeader('Sec-Fetch-Dest'));
		if ($secFetchDest !== '' && $secFetchDest !== 'document') {
			return false;
		}

		return true;
	}
}
