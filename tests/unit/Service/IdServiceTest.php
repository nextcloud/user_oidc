<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\LocalIdService;
use OCA\UserOIDC\Service\ProviderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IdServiceTest extends TestCase {

	/** @var ProviderService | MockObject */
	private $providerService;

	/** @var ProviderMapper | MockObject */
	private $providerMapper;

	/** @var LocalIdService */
	private $idService;

	public function setUp(): void {
		parent::setUp();

		$this->providerService = $this->createMock(ProviderService::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);

		$this->idService = new LocalIdService($this->providerService, $this->providerMapper);
	}

	public function dataGetId() {
		return [
			[1, 'provider1', 'id1', false, false, false, 'id1'],
			[2, 'provider2', 'id2', true, false, false, 'a86d8ab935af1778a321e615db5116e850c85a7a3070049ccd824b8989ccb4d5'],
			[3, 'provider3', 'id3', true, true, false, '9d0fe311ee92abbc64b0b6caf1c9540b128f694f2627a3e926ea22118fa37ebb'],
			[4, 'provider4', 'id4', true, false, true, 'd86655557c03285dc91483102a5935ce8e9e2d424ba6e5ef8b4e6c08cf331275'],
			[5, 'provider5', 'id5', true, true, true, '6c872bc08d76ef132c9ac3fabbcda24cc4866e5a9fd4d2fd592ce3fc2866c972'],
			[6, 'provider6', 'id6', false, true, false, '1d64d63dbda703f2598581b8bfc440e191c7a668dfb1de40c7daf41c6f9204c7'],
			[7, 'provider7', 'id7', false, true, true, '1b517769ab0d33c275bc88747917c38f1e1c7aa2582330fbe2d936fb6cfcabb3'],
			[8, 'provider8', 'id8', false, false, true, 'provider8-id8'],
		];
	}

	/** @dataProvider dataGetId */
	public function testGetId(int $providerId, string $providerName, string $id, bool $id4me, bool $uniqueId, bool $providerBasedId, string $expected): void {
		$provider = new Provider();
		$provider->setIdentifier($providerName);

		$this->providerMapper->method('getProvider')->willReturn($provider);

		$this->providerService
			->method('getSetting')
			->will($this->returnValueMap(
				[
					[$providerId, ProviderService::SETTING_UNIQUE_UID, '1', $uniqueId ? '1' : '0'],
					[$providerId, ProviderService::SETTING_PROVIDER_BASED_ID, '0', $providerBasedId ? '1' : '0'],
				]
			));

		$result = $this->idService->getId($providerId, $id, $id4me);

		$this->assertEquals($expected, $result);
	}
}
