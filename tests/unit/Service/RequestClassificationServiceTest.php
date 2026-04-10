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
			'top level navigation with html accept' => [
				'GET',
				[
					'Accept' => 'text/html,application/xhtml+xml',
					'Sec-Fetch-Mode' => 'navigate',
					'Sec-Fetch-Dest' => 'document',
				],
				true,
			],
			'html accept without fetch metadata' => [
				'GET',
				[
					'Accept' => 'text/html',
				],
				true,
			],
			'xhr request' => [
				'GET',
				[
					'Accept' => 'text/html',
					'X-Requested-With' => 'XMLHttpRequest',
				],
				false,
			],
			'json request' => [
				'GET',
				[
					'Accept' => 'application/json',
				],
				false,
			],
			'non navigate fetch mode' => [
				'GET',
				[
					'Accept' => 'text/html',
					'Sec-Fetch-Mode' => 'cors',
				],
				false,
			],
			'non document destination' => [
				'GET',
				[
					'Accept' => 'text/html',
					'Sec-Fetch-Dest' => 'empty',
				],
				false,
			],
			'non get request' => [
				'POST',
				[
					'Accept' => 'text/html',
				],
				false,
			],
		];
	}
}
