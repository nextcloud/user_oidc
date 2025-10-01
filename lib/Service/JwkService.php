<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

require_once __DIR__ . '/../../vendor/autoload.php';

use Strobotti\JWK\KeyFactory;

class JwkService {

	public function __construct(
	) {

	}

	public function getJwks(): array {
		$keyPair = $this->createKeyPair();

		$options = [
			'use' => 'sig',
			'alg' => 'sha512',
			'kid' => 'plop',
		];
		$keyFactory = new KeyFactory();
		$publicJwk = $keyFactory->createFromPem($keyPair['public'], $options);
		// $privateJwk = $keyFactory->createFromPem($keyPair['private'], $options);
		return [
			'public' => $publicJwk,
			//'private' => $privateJwk,
		];
	}

	public function createKeyPair(): array {
		$config = [
			'digest_alg' => 'sha512',
			'private_key_bits' => 4096,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		];

		// Create the private and public key
		$key = openssl_pkey_new($config);
		openssl_pkey_export($key, $privKeyPem);
		$pubKey = openssl_pkey_get_details($key);
		$pubKeyPem = $pubKey['key'];

		return [
			'public' => $pubKeyPem,
			'private' => $privKeyPem,
		];
	}
}
