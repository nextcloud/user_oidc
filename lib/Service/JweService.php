<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

require_once __DIR__ . '/../../vendor/autoload.php';

use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A192CBCHS384;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use Jose\Component\Encryption\Algorithm\KeyEncryption\A256KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA192KW;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\JWELoader;
use Jose\Component\Encryption\JWETokenSupport;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;

class JweService {

	public const CONTENT_ENCRYPTION_ALGORITHM = 'A192CBC-HS384';

	public function __construct(
		private JwkService $jwkService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $payload the content of the JWE
	 * @param array $encryptionJwk the public key in JWK format
	 * @param string $keyEncryptionAlg the algorithm to use for the key encryption
	 * @param string $contentEncryptionAlg the algorithm to use for the content encryption
	 * @return string
	 */
	public function createSerializedJweWithKey(
		string $payload, array $encryptionJwk,
		string $keyEncryptionAlg = JwkService::PEM_ENC_KEY_ALGORITHM,
		string $contentEncryptionAlg = self::CONTENT_ENCRYPTION_ALGORITHM,
	): string {
		$algorithmManager = new AlgorithmManager([
			new A256KW(),
			new A256CBCHS512(),
			new ECDHESA192KW(),
			new A192CBCHS384(),
		]);

		// The compression method manager with the DEF (Deflate) method.
		//$compressionMethodManager = new CompressionMethodManager([
		//    new Deflate(),
		//]);

		// We instantiate our JWE Builder.
		$jweBuilder = new JWEBuilder(
			$algorithmManager,
		);

		// Our key.
		$jwk = new JWK($encryptionJwk);

		$jwe = $jweBuilder
			->create()            // We want to create a new JWE
			->withPayload($payload) // We set the payload
			->withSharedProtectedHeader([
				// Key Encryption Algorithm
				// 'alg' => 'A256KW',
				'alg' => $keyEncryptionAlg,
				// Content Encryption Algorithm
				// 'enc' => 'A256CBC-HS512',
				'enc' => $contentEncryptionAlg,
				//'zip' => 'DEF'            // Not recommended.
				'cty' => 'JWT',
			])
			->addRecipient($jwk)    // We add a recipient (a shared key or public key).
			->build();

		$serializer = new CompactSerializer();
		// We serialize the recipient at index 0 (we only have one recipient).
		return $serializer->serialize($jwe, 0);
	}

	/**
	 * @param string $serializedJwe the JWE token
	 * @param array $jwkArray the private key in JWK format (with the 'd' attribute)
	 * @return string
	 * @throws \Exception
	 */
	public function decryptSerializedJweWithKey(string $serializedJwe, array $jwkArray): string {
		$algorithmManager = new AlgorithmManager([
			new A256KW(),
			new A256CBCHS512(),
			new ECDHESA192KW(),
			new A192CBCHS384(),
		]);

		// The compression method manager with the DEF (Deflate) method.
		//$compressionMethodManager = new CompressionMethodManager([
		//    new Deflate(),
		//]);

		// We instantiate our JWE Decrypter.
		$jweDecrypter = new JWEDecrypter(
			$algorithmManager,
		);

		// Our key.
		$jwk = new JWK($jwkArray);

		// The serializer manager. We only use the JWE Compact Serialization Mode.
		$serializerManager = new JWESerializerManager([
			new CompactSerializer(),
		]);

		// ----------- OPTION 1
		/*
		// We try to load the token.
		$jwe = $serializerManager->unserialize($serializedJwe);

		// We decrypt the token. This method does NOT check the header.
		$success = $jweDecrypter->decryptUsingKey($jwe, $jwk, 0);
		*/

		// ----------- OPTION 2
		$headerCheckerManager = new HeaderCheckerManager(
			// Provide the allowed algorithms using the previously created
			// AlgorithmManager.
			[
				new AlgorithmChecker(
					// $keyEncryptionAlgorithmManager->list()
					$algorithmManager->list(),
				),
			],
			// Provide the appropriate TokenTypeSupport[].
			[
				new JWETokenSupport(),
			]
		);

		// no idea why TooManyArguments is thrown by psalm
		/** @psalm-suppress TooManyArguments */
		$jweLoader = new JWELoader(
			$serializerManager,
			$jweDecrypter,
			$headerCheckerManager,
		);

		$jwe = $jweLoader->loadAndDecryptWithKey($serializedJwe, $jwk, $recipient);
		$payload = $jwe->getPayload();
		if ($payload === null) {
			throw new \Exception('Could not decrypt JWE, payload is null');
		}

		return $payload;
	}

	public function decryptSerializedJwe(string $serializedJwe): string {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey(true);
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		$encryptionPrivateJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true, includePrivateKey: true);

		try {
			return $this->decryptSerializedJweWithKey($serializedJwe, $encryptionPrivateJwk);
		} catch (\Exception $e) {
			// try the old expired key
			$oldPemEncryptionKey = $this->appConfig->getAppValueString(JwkService::PEM_EXPIRED_ENC_KEY_SETTINGS_KEY, lazy: true);
			$oldPemEncryptionKeyCreatedAt = $this->appConfig->getAppValueInt(JwkService::PEM_EXPIRED_ENC_KEY_CREATED_AT_SETTINGS_KEY, lazy: true);
			if ($oldPemEncryptionKey === '' || $oldPemEncryptionKeyCreatedAt === 0) {
				$this->logger->debug('JWE decryption failed with a fresh key and there is no old key');
				throw $e;
			}
			// the old encryption key is expired for more than an hour, we can't use it
			if (time() > $oldPemEncryptionKeyCreatedAt + JwkService::PEM_ENC_KEY_EXPIRES_AFTER_SECONDS + (60 * 60)) {
				$this->logger->debug('JWE decryption failed with a fresh key and the old key is expired for more than an hour');
				throw $e;
			}
			$oldSslEncryptionKey = openssl_pkey_get_private($oldPemEncryptionKey);
			$oldSslEncryptionKeyDetails = openssl_pkey_get_details($oldSslEncryptionKey);
			$oldEncryptionPrivateJwk = $this->jwkService->getJwkFromSslKey($oldSslEncryptionKeyDetails, isEncryptionKey: true, includePrivateKey: true);
			return $this->decryptSerializedJweWithKey($serializedJwe, $oldEncryptionPrivateJwk);
		}
	}

	public function createSerializedJwe(string $payload): string {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey(true);
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		$encPublicJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true);

		return $this->createSerializedJweWithKey($payload, $encPublicJwk);
	}

	public function debug(): array {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey(true);
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		$encPublicJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true);
		$encPrivJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true, includePrivateKey: true);

		$payloadArray = [
			'iat' => time(),
			'nbf' => time(),
			'exp' => time() + 3600,
			'iss' => 'My service',
			'aud' => 'Your application',
		];

		/*
		$exampleJwkArray = [
			'kty' => 'oct',
			'k' => 'dzI6nbW4OcNF-AtfxGAmuyz7IpHRudBI0WgGjZWgaRJt6prBn3DARXgUR8NVwKhfL43QBIU2Un3AvCGCHRgY4TbEqhOi8-i98xxmCggNjde4oaW6wkJ2NgM3Ss9SOX9zS3lcVzdCMdum-RwVJ301kbin4UtGztuzJBeg5oVN00MGxjC2xWwyI0tgXVs-zJs5WlafCuGfX1HrVkIf5bvpE0MQCSjdJpSeVao6-RSTYDajZf7T88a2eVjeW31mMAg-jzAWfUrii61T_bYPJFOXW8kkRWoa1InLRdG6bKB9wQs9-VdXZP60Q4Yuj_WZ-lO7qV9AEFrUkkjpaDgZT86w2g',
		];
		$serializedJweToken = $this->createSerializedJwe($payloadArray, $exampleJwkArray);
		$decryptedJweString = $this->decryptSerializedJwe($serializedJweToken, $exampleJwkArray);
		*/
		$serializedJweToken = $this->createSerializedJweWithKey(json_encode($payloadArray), $encPublicJwk);
		$jwtParts = explode('.', $serializedJweToken, 3);
		$jwtHeader = json_decode(base64_decode($jwtParts[0]), true);
		$decryptedJweString = $this->decryptSerializedJweWithKey($serializedJweToken, $encPrivJwk);

		return [
			'public_key' => $encPublicJwk,
			'private_key' => $encPrivJwk,
			'input_payloadArray' => $payloadArray,
			'input_serializedJweToken' => $serializedJweToken,
			'jwe_header' => $jwtHeader,
			'output_payloadArray' => json_decode($decryptedJweString, true),
		];
	}
}
