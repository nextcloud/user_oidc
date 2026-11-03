<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Helper;

class TokenClaims {

	/**
	 * A missing aud never matches.
	 *
	 * @see https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation
	 */
	public static function audienceContains(object $payload, string $clientId): bool {
		$aud = $payload->aud ?? null;
		return $aud === $clientId || (is_array($aud) && in_array($clientId, $aud, true));
	}
}
