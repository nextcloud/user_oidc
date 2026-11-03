<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCP\IRequest;

class RequestClassificationService {
	/**
	 * Whether the request is a real top-level browser page navigation (typing a URL, clicking a
	 * link, reloading) as opposed to a background fetch()/XHR (notifications polling, heartbeats,
	 * dashboard widgets, …).
	 *
	 * This gates the disruptive logout+redirect to the OIDC login flow, so it MUST NOT
	 * misclassify a background request as a navigation: doing so terminates the session and makes
	 * the web UI reload the page, losing unsaved work (see #1449). Relying on the absence of
	 * X-Requested-With / OCS-apirequest is not enough — Nextcloud's background fetcher and various
	 * fetch()-based polls do not set those headers.
	 */
	public static function isTopLevelHtmlNavigation(IRequest $request): bool {
		if (strtoupper($request->getMethod()) !== 'GET') {
			return false;
		}

		// Speculative loads (prefetch/prerender) carry navigation-like Fetch Metadata but are not
		// user-visible navigations; triggering the logout+redirect from one would invisibly
		// terminate the session. Sec-Purpose is the standard marker, Purpose the legacy one.
		if (stripos($request->getHeader('Sec-Purpose') . $request->getHeader('Purpose'), 'prefetch') !== false
			|| stripos($request->getHeader('Sec-Purpose'), 'prerender') !== false) {
			return false;
		}

		// Fetch Metadata (https://www.w3.org/TR/fetch-metadata/): modern browsers send these on
		// every request. Only a real top-level navigation produces Sec-Fetch-Mode: navigate
		// together with Sec-Fetch-Dest: document; background fetch()/XHR send
		// Sec-Fetch-Mode: cors|same-origin|no-cors and Sec-Fetch-Dest: empty.
		// When the client sends Fetch Metadata, trust it.
		$secFetchMode = $request->getHeader('Sec-Fetch-Mode');
		$secFetchDest = $request->getHeader('Sec-Fetch-Dest');
		if ($secFetchMode !== '' || $secFetchDest !== '') {
			return $secFetchMode === 'navigate' && $secFetchDest === 'document';
		}

		// Fallback for clients without Fetch Metadata (older browsers, some non-browser clients):
		// exclude known API/XHR markers and require an HTML Accept header, since browser document
		// navigations always send "Accept: text/html, …" while JSON polls do not.
		if ($request->getHeader('OCS-apirequest') !== '') {
			return false;
		}

		if ($request->getHeader('X-Requested-With') === 'XMLHttpRequest') {
			return false;
		}

		return str_contains(strtolower($request->getHeader('Accept')), 'text/html');
	}
}
