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

	/**
	 * Detects a browser speculative preload (prefetch/prerender/preview) of a request,
	 * as opposed to a request the user actually navigated to.
	 *
	 * This exists because a speculatively preloaded GET to the OIDC login or callback
	 * URLs would mint or consume the single-use OIDC login state before the user ever
	 * clicked anything. `LoginController::code()` deletes that state - and regenerates
	 * the session - as soon as it is consumed, so by the time the user's real click
	 * lands, it is handed a session that has already been destroyed, producing a
	 * spurious "Access forbidden" instead of a successful login.
	 *
	 * These headers are entirely client-controlled and trivially spoofed by anyone
	 * sending the request directly (e.g. with curl). This method MUST NEVER be used to
	 * suppress rate limiting, throttling, or brute-force protection - only to decide
	 * whether to short-circuit a request before it touches session state, where being
	 * wrong merely costs the browser a discarded speculative response and a retry.
	 *
	 * Each header is parsed as a list of tokens rather than compared with `===`,
	 * because real browsers combine multiple purposes into one header value. Chromium
	 * sends `Sec-Purpose: prefetch;prerender` for a prerendered navigation - not a bare
	 * `prerender` - so an equality check would silently never match a prerender.
	 *
	 * @param IRequest $request the incoming request to classify
	 * @return bool true if the request looks like a speculative preload rather than a
	 *              real user navigation
	 *
	 * @example A Chromium prerender of the OIDC callback sends
	 *          `Sec-Purpose: prefetch;prerender`, so
	 *          `isSpeculativeRequest($request)` returns `true` and the caller can
	 *          reject it before it consumes the single-use login state.
	 */
	public static function isSpeculativeRequest(IRequest $request): bool {
		$headerTokenMatches = [
			'Sec-Purpose' => ['prefetch', 'prerender'],
			'Purpose' => ['prefetch'],
			'X-Purpose' => ['preview'],
			'X-Moz' => ['prefetch'],
		];

		foreach ($headerTokenMatches as $header => $matchingTokens) {
			$tokens = self::splitHeaderTokens($request->getHeader($header));
			if (array_intersect($tokens, $matchingTokens) !== []) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Splits a header value into lowercase, trimmed tokens on `;` and `,`, so that
	 * combined values like `prefetch;prerender` are matched per-token instead of
	 * requiring an exact whole-value match.
	 *
	 * @param string $headerValue the raw header value, possibly empty
	 * @return string[] the lowercase, trimmed, non-empty tokens found in the header
	 */
	private static function splitHeaderTokens(string $headerValue): array {
		if ($headerValue === '') {
			return [];
		}

		$tokens = preg_split('/[;,]/', $headerValue) ?: [];

		return array_values(array_filter(array_map(
			static fn (string $token): string => strtolower(trim($token)),
			$tokens,
		), static fn (string $token): bool => $token !== ''));
	}
}
