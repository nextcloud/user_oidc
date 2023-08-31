<?php

declare(strict_types=1);

namespace OCA\UserOIDC\BaseTest;

use OCP\AppFramework\App;

use PHPUnit\Framework\TestCase;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\MagentaBearer\TokenService;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;

use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWS;

use Jose\Component\Encryption\Algorithm\KeyEncryption\PBES2HS512A256KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA256KW;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;

use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEBuilder;

/**
 * This test must be run with --stderr, e.g.
 * phpunit --stderr --bootstrap tests/bootstrap.php tests/unit/SlupReceiverTest.php
 */
class BearerTokenTestCase extends TestCase {

	/** @var App */
	protected $app;

	/**
	 * @var TokenService
	 */
	protected $tokenToService;

	/**
	 * Real world example claims content
	 */
	private $realExampleClaims;

	public function getRealExampleClaims() : array {
		return $this->realExampleClaims;
	}

	/**
     * Test bearer secret
	 */
	public function getTestBearerSecret() {
		return \Base64Url\Base64Url::encode('JQ17C99A-DAF8-4E27-FBW4-GV23B043C993');
	}


	public function setUp(): void {
		parent::setUp();

		$this->app = new App(Application::APP_ID);

		$this->tokenService = $this->app->getContainer()->get(TokenService::class);
		$this->realExampleClaims = array(
			'iss' => 'sts00.idm.ver.sul.t-online.de',
			'urn:telekom.com:idm:at:subjectType' => array(
				'format' => 'urn:com:telekom:idm:1.0:nameid-format:anid',
				'realm' => 'ver.sul.t-online.de'
			),
			'acr' => 'urn:telekom:names:idm:THO:1.0:ac:classes:pwd',
			'sub' => '1200490100000000100XXXXX',
			'iat' => time(),
			'nbf' => time(),
			'exp' => time() + 7200,
			'urn:telekom.com:idm:at:authNStatements' => array(
				'urn:telekom:names:idm:THO:1.0:ac:classes:pwd' => array(
					'authenticatingAuthority' => null,
					'authNInstant' => time() )
			),
			'aud' => ['http://auth.magentacloud.de'],
			'jti' => 'STS-1e22a06f-790c-40fb-ad1d-6de2ddcf2431',
			'urn:telekom.com:idm:at:attributes' => [
				array( 'name' => 'client_id',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '10TVL0SAM30000004901NEXTMAGENTACLOUDTEST'),
				array( 'name' => 'displayname',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => 'nmc01@ver.sul.t-online.de'),
				array( 'name' => 'email',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => 'nmc01@ver.sul.t-online.de'),
				array( 'name' => 'anid',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '1200490100000000100XXXXX'),
				array( 'name' => 'd556',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'domt',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => 'ver.sul.t-online.de'),
				array( 'name' => 'f048',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '1'),
				array( 'name' => 'f049',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '1'),
				array( 'name' => 'f051',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'f460',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'f467',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'f468',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'f469',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'f471',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'f556',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '1'),
				array( 'name' => 'f734',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'mainEmail',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => 'nmc01@ver.sul.t-online.de'),
				array( 'name' => 's556',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '0'),
				array( 'name' => 'usta',
					'nameFormat' => 'urn:com:telekom:idm:1.0:attrname-format:field',
					'value' => '1')],
			'urn:telekom.com:idm:at:version' => '1.0');
	}
		
	protected function signToken(array $claims, string $signKey, bool $invalidate = false) : JWS {
		// The algorithm manager with the HS256 algorithm.
		$algorithmManager = new AlgorithmManager([
			new HS256(),
		]);

		if (!$invalidate) {
			$jwk = new JWK([
				'kty' => 'oct',
				'k' => $signKey]);
		} else {
			// use a different key for an invalid signature
			$jwk = new JWK([
				'kty' => 'oct',
				'k' => 'BnWHlEdffC0hfKSxrh01g7/M3djHIiOU6jNwJChYWP8=']);
		}
		// We instantiate our JWS Builder.
		$jwsBuilder = new JWSBuilder($algorithmManager);

		$jws = $jwsBuilder->create()                               // We want to create a new JWS
							->withPayload(json_encode($claims))                   // We set the payload
							->addSignature($jwk, ['alg' => 'HS256']) // We add a signature with a simple protected header
							->build();

		return $jws;
	}

	protected function setupSignedToken(array $claims, string $signKey) {
		$serializer = new \Jose\Component\Signature\Serializer\CompactSerializer();
		return $serializer->serialize($this->signToken($claims, $signKey), 0);
	}

	protected function setupEncryptedToken(JWS $token, string $decryptKey) {
		// The key encryption algorithm manager with the A256KW algorithm.
		$keyEncryptionAlgorithmManager = new AlgorithmManager([
			new PBES2HS512A256KW(),
			new RSAOAEP256(),
			new ECDHESA256KW()
		]);
		// The content encryption algorithm manager with the A256CBC-HS256 algorithm.
		$contentEncryptionAlgorithmManager = new AlgorithmManager([
			new A256CBCHS512(),
		]);
		// The compression method manager with the DEF (Deflate) method.
		$compressionMethodManager = new CompressionMethodManager([
			new Deflate(),
		]);
		$signSerializer = new \Jose\Component\Signature\Serializer\CompactSerializer();

		$jwk = new JWK([
			'kty' => 'oct',
			'k' => $decryptKey]);

		// We instantiate our JWE Builder.
		$jweBuilder = new JWEBuilder(
				$keyEncryptionAlgorithmManager,
				$contentEncryptionAlgorithmManager,
				$compressionMethodManager
			);

		$jwe = $jweBuilder
			->create()                                         // We want to create a new JWE
			->withPayload($signSerializer->serialize($token, 0)) // We set the payload
			->withSharedProtectedHeader([
				'alg' => 'PBES2-HS512+A256KW',                // Key Encryption Algorithm
				'enc' => 'A256CBC-HS512',                     // Content Encryption Algorithm
				'zip' => 'DEF'                                // We enable the compression (just for the example).
			])
			->addRecipient($jwk)
			->build();              // We build it

		$encryptionSerializer = new \Jose\Component\Encryption\Serializer\CompactSerializer(); // The serializer
		return $encryptionSerializer->serialize($jwe, 0);
	}


	protected function setupSignEncryptToken(array $claims, string $secret, bool $invalidate = false) {
		return $this->setupEncryptedToken($this->signToken($claims, $secret, $invalidate), $secret);
	}
}
