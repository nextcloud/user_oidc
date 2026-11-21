<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Migration\Version081301Date20261106120000;
use OCA\UserOIDC\Service\ProviderService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class Version081301Date20261106120000Test extends TestCase {

	private const SUB_PROVIDER = 9101;
	private const USERNAME_PROVIDER = 9102;
	private const DELETED_PROVIDER = 9103;

	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->deleteRows();
	}

	protected function tearDown(): void {
		$this->deleteRows();
		parent::tearDown();
	}

	public function testClearsTheKeysThatHoldTheUid(): void {
		$this->insertRows();

		$this->migrate([]);

		$this->assertSame([self::SUB_PROVIDER, '8f1d0c'], $this->getKey('migration-test-sub'));
		$this->assertSame([null, null], $this->getKey('migration-test-username'));
		// a deleted provider's uid claim is unknown
		$this->assertSame([null, null], $this->getKey('migration-test-deleted'));
	}

	public function testClearsAllKeysWithAUserIdRegexp(): void {
		$this->insertRows();

		$this->migrate(['user_id_regexp' => '/^(.*)@example\.com$/']);

		$this->assertSame([null, null], $this->getKey('migration-test-sub'));
		$this->assertSame([null, null], $this->getKey('migration-test-username'));
	}

	private function migrate(array $oidcSystemConfig): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('user_oidc', [])->willReturn($oidcSystemConfig);
		$providerService = $this->createMock(ProviderService::class);
		$providerService->method('getSetting')
			->willReturnCallback(fn (int $providerId, string $key, string $default = ''): string => match (true) {
				$key !== ProviderService::SETTING_MAPPING_UID => $default,
				$providerId === self::USERNAME_PROVIDER => 'preferred_username',
				default => $default,
			});

		$providerMapper = $this->createMock(ProviderMapper::class);
		$providerMapper->method('getProviders')->willReturn(array_map(static function (int $id): Provider {
			$provider = new Provider();
			$provider->setId($id);
			return $provider;
		}, [self::SUB_PROVIDER, self::USERNAME_PROVIDER]));

		$migration = new Version081301Date20261106120000($this->db, $config, $providerService, $providerMapper, $this->createMock(LoggerInterface::class));
		$migration->postSchemaChange($this->createMock(IOutput::class), fn () => $this->createMock(ISchemaWrapper::class), []);
	}

	private function insertRows(): void {
		$this->insertRow('migration-test-sub', self::SUB_PROVIDER, '8f1d0c');
		$this->insertRow('migration-test-username', self::USERNAME_PROVIDER, 'migration-test-username');
		$this->insertRow('migration-test-deleted', self::DELETED_PROVIDER, 'alice');
	}

	private function insertRow(string $userId, ?int $providerId, ?string $sub): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('user_oidc')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter(''),
				'provider_id' => $qb->createNamedParameter($providerId, $providerId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'sub' => $qb->createNamedParameter($sub, $sub === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
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
			->where($qb->expr()->in('user_id', $qb->createNamedParameter(
				['migration-test-sub', 'migration-test-username', 'migration-test-deleted'],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->executeStatement();
	}
}
