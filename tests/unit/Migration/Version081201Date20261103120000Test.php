<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Migration\Version081201Date20261103120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class Version081201Date20261103120000Test extends TestCase {

	private IDBConnection $db;
	private string $id4meUserId;
	private string $oidcUserId;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->id4meUserId = hash('sha256', '1_1_migration-test-alice');
		$this->oidcUserId = hash('sha256', '1_0_migration-test-alice');
		$this->deleteRows();
	}

	protected function tearDown(): void {
		$this->deleteRows();
		parent::tearDown();
	}

	public function testClearsOnlyId4meAccounts(): void {
		$this->insertRow($this->id4meUserId, 1, 'migration-test-alice');
		$this->insertRow($this->oidcUserId, 1, 'migration-test-alice-oidc');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(self::anything(), self::callback(fn (array $context) => $context['userIds'] === [$this->id4meUserId]));

		$migration = new Version081201Date20261103120000($this->db, $logger);
		$migration->postSchemaChange($this->createMock(IOutput::class), fn () => $this->createMock(ISchemaWrapper::class), []);

		$this->assertSame([null, null], $this->getKey($this->id4meUserId));
		$this->assertSame([1, 'migration-test-alice-oidc'], $this->getKey($this->oidcUserId));
	}

	private function insertRow(string $userId, int $providerId, string $sub): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('user_oidc')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter(''),
				'provider_id' => $qb->createNamedParameter($providerId, IQueryBuilder::PARAM_INT),
				'sub' => $qb->createNamedParameter($sub),
			])
			->executeStatement();
	}

	private function getKey(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('provider_id', 'sub')
			->from('user_oidc')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$row = $qb->executeQuery()->fetch();
		return [$row['provider_id'] === null ? null : (int)$row['provider_id'], $row['sub']];
	}

	private function deleteRows(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('user_oidc')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter([$this->id4meUserId, $this->oidcUserId], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
	}
}
