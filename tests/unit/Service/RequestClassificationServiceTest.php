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
			// Real top-level navigation as sent by a modern browser
			'navigation via fetch metadata' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
					'Accept' => 'text/html,application/xhtml+xml',
				],
				true,
			],
			// Background fetch()/XHR from a modern browser (e.g. dashboard widget)
			'cors fetch via fetch metadata' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'cors',
					'Sec-Fetch-Dest' => 'empty',
				],
				false,
			],
			'same-origin fetch via fetch metadata' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'same-origin',
					'Sec-Fetch-Dest' => 'empty',
				],
				false,
			],
			// The exact #1449 regression: notifications/logreader background polls that do NOT set
			// OCS-apirequest / X-Requested-With but DO send fetch metadata must not look like a navigation
			'background poll without xhr markers' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'cors',
					'Sec-Fetch-Dest' => 'empty',
					'Accept' => 'application/json, text/plain, */*',
				],
				false,
			],
			// navigate mode but not a document destination (e.g. iframe/object) -> do not redirect
			'navigate mode non-document dest' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'iframe',
				],
				false,
			],
			// Speculative loads look like navigations but must never trigger logout+redirect
			'prefetch with navigation metadata' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
					'Sec-Purpose' => 'prefetch',
				],
				false,
			],
			'prerender with navigation metadata' => [
				'GET',
				[
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
					'Sec-Purpose' => 'prefetch;prerender',
				],
				false,
			],
			'legacy purpose prefetch' => [
				'GET',
				[
					'Purpose' => 'prefetch',
					'Accept' => 'text/html,application/xhtml+xml',
				],
				false,
			],
			// Fallback path (no fetch metadata): legacy browser document navigation
			'legacy navigation via accept html' => [
				'GET',
				[
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				],
				true,
			],
			// Fallback path: legacy JSON poll without fetch metadata
			'legacy json poll' => [
				'GET',
				[
					'Accept' => 'application/json, text/plain, */*',
				],
				false,
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
			// Header-less GET (curl, health check, ...) is not a browser navigation
			'bare get without headers' => [
				'GET',
				[],
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
