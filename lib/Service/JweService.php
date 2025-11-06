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

class JweService {

	public function __construct(
		private JwkService $jwkService,
	) {
	}

	public function createSerializedJwe(array $payloadArray, array $encryptionJwk): string {
		// encrypt a JWT payload with the enc key => JWE

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

		// The payload we want to encrypt. It MUST be a string.
		$payload = json_encode($payloadArray);

		$jwe = $jweBuilder
			->create()              // We want to create a new JWE
			->withPayload($payload) // We set the payload
			->withSharedProtectedHeader([
				// Key Encryption Algorithm
				// 'alg' => 'A256KW',
				'alg' => 'ECDH-ES+A192KW',
				// Content Encryption Algorithm
				// 'enc' => 'A256CBC-HS512',
				'enc' => 'A192CBC-HS384',
				//'zip' => 'DEF'            // Not recommended.
			])
			->addRecipient($jwk)    // We add a recipient (a shared key or public key).
			->build();

		$serializer = new CompactSerializer(); // The serializer
		return $serializer->serialize($jwe, 0); // We serialize the recipient at index 0 (we only have one recipient).
	}

	public function decryptSerializedJwe(string $serializedJwe, array $jwkArray): string {
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
					$algorithmManager->list()
				)
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

	public function debug(): array {
		// get encryption key, both formats
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
		$serializedJweToken = $this->createSerializedJwe($payloadArray, $encPublicJwk);
		$decryptedJweString = $this->decryptSerializedJwe($serializedJweToken, $encPrivJwk);

		return [
			'input_payloadArray' => $payloadArray,
			'input_serializedJweToken' => $serializedJweToken,
			'output_payloadArray' => json_decode($decryptedJweString, true),
		];
	}
}
