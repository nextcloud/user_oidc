<?php
/*
 * @copyright Copyright (c) 2021 T-Systems International
 *
 * @author Bernd Rederlechner <bernd.rederlechner@t-systems.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

use OCA\UserOIDC\BaseTest\BearerTokenTestCase;

use OCP\IConfig;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\MagentaBearer\SignatureException;
use OCA\UserOIDC\MagentaBearer\InvalidTokenException;

use OCA\UserOIDC\MagentaBearer\TokenService;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;


class SamBearerTokenTest extends BearerTokenTestCase {

	/**
	 * @var ProviderService
	 */
	private $provider;


	public function setUp(): void {
		parent::setUp();
	}

	public function testValidSignature() {
        $this->expectNotToPerformAssertions();
		$testtoken = $this->setupSignedToken($this->getRealExampleClaims(), $this->getTestBearerSecret());
		//fwrite(STDERR, '[' . $testtoken . ']');
		$bearerToken = $this->tokenService->decryptToken($testtoken, $this->getTestBearerSecret());
		$this->tokenService->verifySignature($bearerToken, $this->getTestBearerSecret());
		$claims = $this->tokenService->decode($bearerToken);
		$this->tokenService->verifyClaims($claims, ['http://auth.magentacloud.de']);
    }

	public function testInvalidSignature() {
		$this->expectException(SignatureException::class);
		$testtoken = $this->setupSignedToken($this->getRealExampleClaims(), $this->getTestBearerSecret());
		$invalidSignToken = mb_substr($testtoken, 0, -1); // shorten sign to invalidate
		// fwrite(STDERR, '[' . $testtoken . ']');
		$bearerToken = $this->tokenService->decryptToken($invalidSignToken, $this->getTestBearerSecret());
		$this->tokenService->verifySignature($bearerToken, $this->getTestBearerSecret());
		$claims = $this->tokenService->decode($bearerToken);
		$this->tokenService->verifyClaims($claims, ['http://auth.magentacloud.de']);
	}

	public function testEncryptedValidSignature() {
        $this->expectNotToPerformAssertions();
		$testtoken = $this->setupSignEncryptToken($this->getRealExampleClaims(), $this->getTestBearerSecret());
		//fwrite(STDERR, '[' . $testtoken . ']');
		$bearerToken = $this->tokenService->decryptToken($testtoken, $this->getTestBearerSecret());
		$this->tokenService->verifySignature($bearerToken, $this->getTestBearerSecret());
		$claims = $this->tokenService->decode($bearerToken);
		$this->tokenService->verifyClaims($claims, ['http://auth.magentacloud.de']);
    }

	public function testEncryptedInvalidEncryption() {
		$this->expectException(InvalidTokenException::class);
		$testtoken = $this->setupSignEncryptToken($this->getRealExampleClaims(), $this->getTestBearerSecret());
		$invalidEncryption = mb_substr($testtoken, 0, -1); // shorten sign to invalidate
		//fwrite(STDERR, '[' . $testtoken . ']');
		$bearerToken = $this->tokenService->decryptToken($invalidEncryption, $this->getTestBearerSecret());
		$this->tokenService->verifySignature($bearerToken, $this->getTestBearerSecret());
		$claims = $this->tokenService->decode($bearerToken);
		$this->tokenService->verifyClaims($claims, ['http://auth.magentacloud.de']);
    }


}
