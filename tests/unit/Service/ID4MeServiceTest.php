<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\AuditLog\AuditLogger;
use OCA\UserOIDC\AuditLog\Operation;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ID4MeService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ID4MeServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private AuditLogger&MockObject $auditLogger;
	private ID4MeService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->auditLogger = $this->createMock(AuditLogger::class);

		$this->service = new ID4MeService(
			$this->appConfig,
			$this->createMock(DiscoveryService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(HttpClientHelper::class),
			$this->createMock(ICacheFactory::class),
			$this->auditLogger,
		);
	}

	public static function dataSetID4ME(): array {
		return [
			'enable' => [true, '1', 'Login with ID4me was enabled'],
			'disable' => [false, '0', 'Login with ID4me was disabled'],
		];
	}

	#[DataProvider('dataSetID4ME')]
	public function testSetID4ME(bool $enabled, string $storedValue, string $message): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, 'id4me_enabled', $storedValue, true);
		$this->auditLogger->expects($this->once())
			->method('log')
			->with(Operation::Id4meUpdated, $message, ['enabled' => $enabled]);

		$this->service->setID4ME($enabled);
	}
}
