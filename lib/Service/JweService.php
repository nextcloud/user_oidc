<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\Vendor\Jose\Component\Checker\AlgorithmChecker;
use OCA\UserOIDC\Vendor\Jose\Component\Checker\HeaderCheckerManager;
use OCA\UserOIDC\Vendor\Jose\Component\Core\AlgorithmManager;
use OCA\UserOIDC\Vendor\Jose\Component\Core\JWK;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\Algorithm\ContentEncryption\A192CBCHS384;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\Algorithm\KeyEncryption\A256KW;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA192KW;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\JWEBuilder;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\JWEDecrypter;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\JWELoader;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\JWETokenSupport;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\Serializer\CompactSerializer;
use OCA\UserOIDC\Vendor\Jose\Component\Encryption\Serializer\JWESerializerManager;
use Psr\Log\LoggerInterface;

class JweService {

	public const CONTENT_ENCRYPTION_ALGORITHM = 'A192CBC-HS384';

	public function __construct(
		private JwkService $jwkService,
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
			] + (isset($encryptionJwk['kid']) ? ['kid' => $encryptionJwk['kid']] : []))
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

	/**
	 * Decrypt a JWE with one of our encryption keys, trying the one matching the JWE 'kid' header first
	 *
	 * @param string $serializedJwe
	 * @return string
	 * @throws \Exception
	 */
	public function decryptSerializedJwe(string $serializedJwe): string {
		$privateJwks = $this->jwkService->getDecryptionJwks();
		$kid = $this->getKeyId($serializedJwe);
		if ($kid !== null) {
			usort($privateJwks, static fn (array $a, array $b) => ($b['kid'] === $kid) <=> ($a['kid'] === $kid));
		}

		$lastException = null;
		foreach ($privateJwks as $privateJwk) {
			try {
				return $this->decryptSerializedJweWithKey($serializedJwe, $privateJwk);
			} catch (\Exception $e) {
				$lastException = $e;
			}
		}
		$this->logger->debug('JWE decryption failed with all our encryption keys', ['kid' => $kid]);
		throw $lastException ?? new \Exception('No encryption key available to decrypt the JWE');
	}

	private function getKeyId(string $serializedJwe): ?string {
		$header = json_decode(JWT::urlsafeB64Decode(explode('.', $serializedJwe, 2)[0]), true);
		return is_array($header) && isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;
	}

	public function createSerializedJwe(string $payload): string {
		$myPemEncryptionKey = $this->jwkService->getMyEncryptionKey();
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		$encPublicJwk = $this->jwkService->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true);

		return $this->createSerializedJweWithKey($payload, $encPublicJwk);
	}
}
