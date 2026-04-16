<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Service\RequestClassificationService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestClassificationServiceTest extends TestCase {
	#[DataProvider('topLevelHtmlNavigationProvider')]
	public function testIsTopLevelHtmlNavigation(string $method, array $headers, bool $expected): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')
			->willReturn($method);
		$request->method('getHeader')
			->willReturnCallback(static function (string $name) use ($headers): string {
				return $headers[$name] ?? '';
			});

		self::assertSame($expected, RequestClassificationService::isTopLevelHtmlNavigation($request));
	}

	public static function topLevelHtmlNavigationProvider(): array {
		return [
			'top level navigation' => [
				'GET',
				[],
				true,
			],
			'xhr request' => [
				'GET',
				[
					'X-Requested-With' => 'XMLHttpRequest',
				],
				false,
			],
			'ocs api request' => [
				'GET',
				[
					'OCS-apirequest' => 'true',
				],
				false,
			],
			'non get request' => [
				'POST',
				[],
				false,
			],
		];
	}
}
