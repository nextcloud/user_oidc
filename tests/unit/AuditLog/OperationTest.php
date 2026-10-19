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
		return array_map(static fn (Operation $operation): array => [$operation], Operation::cases());
	}

	#[DataProvider('dataOperations')]
	public function testOperationFormat(Operation $operation): void {
		$this->assertMatchesRegularExpression('/^user_oidc\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $operation->value);
		$this->assertNotSame('', $operation->description());
	}
}
