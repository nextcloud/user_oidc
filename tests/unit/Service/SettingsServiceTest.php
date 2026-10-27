<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SettingsServiceTest extends TestCase {

	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private SettingsService $settingsService;

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService = new SettingsService(
			$this->createMock(IAppConfig::class),
			$this->config,
			$this->logger,
		);
	}

	public static function dataParseUserId(): array {
		return [
			'no config' => [[], '123-abc-123', '123-abc-123'],
			'empty regexp' => [['user_id_regexp' => ''], '123-abc-123', '123-abc-123'],
			'non string regexp' => [['user_id_regexp' => ['/abc/']], '123-abc-123', '123-abc-123'],
			'full match' => [['user_id_regexp' => '/[a-zA-Z]+/'], '123-abc-345-def', 'abc'],
			'capture group' => [['user_id_regexp' => '/u=([^,]+)/'], 'anything,u=123,anything,u=345', '123'],
			'modifiers' => [['user_id_regexp' => '/U=([^,]+)/i'], 'u=123', '123'],
			'no match' => [['user_id_regexp' => '/u=([^,]+)/'], 'abc', 'abc'],
		];
	}

	#[DataProvider('dataParseUserId')]
	public function testParseUserId(array $systemConfig, string $userId, string $expected): void {
		$this->config->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn($systemConfig);
		$this->logger->expects($this->never())
			->method('warning');

		$this->assertSame($expected, $this->settingsService->parseUserId($userId));
	}

	public static function dataParseUserIdInvalidRegexp(): array {
		return [
			'missing delimiters' => ['u=([^,]+)'],
			'missing trailing delimiter' => ['/u=([^,]+)'],
			'invalid modifier' => ['/u=([^,]+)/1'],
		];
	}

	#[DataProvider('dataParseUserIdInvalidRegexp')]
	public function testParseUserIdInvalidRegexp(string $regexp): void {
		$this->config->method('getSystemValue')
			->with('user_oidc', [])
			->willReturn(['user_id_regexp' => $regexp]);
		$this->logger->expects($this->once())
			->method('warning');

		$this->assertSame('u=123', $this->settingsService->parseUserId('u=123'));
	}
}
