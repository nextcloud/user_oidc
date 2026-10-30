<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\AuditLog\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationTest extends TestCase {
	public static function dataOperations(): array {
		$constants = (new \ReflectionClass(Operation::class))->getConstants();
		unset($constants['DESCRIPTIONS']);
		return array_map(static fn (string $operation): array => [$operation], $constants);
	}

	#[DataProvider('dataOperations')]
	public function testOperationFormat(string $operation): void {
		$this->assertMatchesRegularExpression('/^user_oidc\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $operation);
		$this->assertNotSame('', Operation::DESCRIPTIONS[$operation] ?? '');
	}
}
