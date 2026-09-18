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

	#[DataProvider('speculativeRequestProvider')]
	public function testIsSpeculativeRequest(array $headers, bool $expected): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')
			->willReturnCallback(static function (string $name) use ($headers): string {
				return $headers[$name] ?? '';
			});

		self::assertSame($expected, RequestClassificationService::isSpeculativeRequest($request));
	}

	public static function speculativeRequestProvider(): array {
		return [
			'no headers at all' => [
				[],
				false,
			],
			'empty header value' => [
				['Sec-Purpose' => ''],
				false,
			],
			'Sec-Purpose prefetch' => [
				['Sec-Purpose' => 'prefetch'],
				true,
			],
			'Sec-Purpose prerender' => [
				['Sec-Purpose' => 'prerender'],
				true,
			],
			'Sec-Purpose prefetch;prerender' => [
				['Sec-Purpose' => 'prefetch;prerender'],
				true,
			],
			'Sec-Purpose prefetch; prerender with space' => [
				['Sec-Purpose' => 'prefetch; prerender'],
				true,
			],
			'Sec-Purpose mixed case' => [
				['Sec-Purpose' => 'Prefetch'],
				true,
			],
			'Purpose prefetch' => [
				['Purpose' => 'prefetch'],
				true,
			],
			'X-Purpose preview' => [
				['X-Purpose' => 'preview'],
				true,
			],
			'X-Moz prefetch' => [
				['X-Moz' => 'prefetch'],
				true,
			],
			'unrelated Sec-Purpose value' => [
				['Sec-Purpose' => 'navigate'],
				false,
			],
			'comma separated token list' => [
				['Sec-Purpose' => 'something, prerender'],
				true,
			],
			'unrelated Purpose value' => [
				['Purpose' => 'navigate'],
				false,
			],
			'matching token only in an unrelated header' => [
				['User-Agent' => 'prefetch'],
				false,
			],
		];
	}
}
