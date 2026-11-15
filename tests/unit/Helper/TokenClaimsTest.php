<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Helper\TokenClaims;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TokenClaimsTest extends TestCase {

	public static function audienceProvider(): array {
		return [
			'matching string' => [['aud' => 'nextcloud'], true],
			'other string' => [['aud' => 'other-app'], false],
			'array containing client' => [['aud' => ['other-app', 'nextcloud']], true],
			'array without client' => [['aud' => ['other-app']], false],
			'empty array' => [['aud' => []], false],
			'missing' => [[], false],
			'null' => [['aud' => null], false],
		];
	}

	#[DataProvider('audienceProvider')]
	public function testAudienceContains(array $claims, bool $expected): void {
		$this->assertSame($expected, TokenClaims::audienceContains((object)$claims, 'nextcloud'));
	}
}
