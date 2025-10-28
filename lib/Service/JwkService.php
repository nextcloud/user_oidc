<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

require_once __DIR__ . '/../../vendor/autoload.php';

use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use Strobotti\JWK\Key\KeyInterface;
use Strobotti\JWK\KeyFactory;

class JwkService {

	public const PEM_PRIVATE_KEY_SETTINGS_KEY = 'pemPrivateKey';
	public const PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY = 'pemPrivateKeyExpiresAt';
	public const PEM_PRIVATE_KEY_EXPIRES_IN_SECONDS = 60 * 2;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Get our stored private PEM key (or regenerate it if it's expired)
	 *
	 * @param bool $refresh
	 * @return string
	 * @throws AppConfigTypeConflictException
	 */
	public function getMyPemPrivateKey(bool $refresh = true): string {
		$pemPrivateKey = $this->appConfig->getAppValueString(self::PEM_PRIVATE_KEY_SETTINGS_KEY, lazy: true);
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);

		if ($pemPrivateKey === '' || $pemPrivateKeyExpiresAt === 0 || ($refresh && time() > $pemPrivateKeyExpiresAt)) {
			$pemPrivateKey = $this->generatePemPrivateKey();
			// store the key
			$this->appConfig->setAppValueString(self::PEM_PRIVATE_KEY_SETTINGS_KEY, $pemPrivateKey, lazy: true);
			$this->appConfig->setAppValueInt(self::PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY, time() + self::PEM_PRIVATE_KEY_EXPIRES_IN_SECONDS, lazy: true);
		}
		return $pemPrivateKey;
	}

	/**
	 * Generate a new full/private key and return it in PEM format
	 *
	 * @return string
	 */
	public function generatePemPrivateKey(): string {
		$config = [
			// 'digest_alg' => 'sha512',
			// 'private_key_bits' => 4096,
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			// 'private_key_type' => OPENSSL_KEYTYPE_RSA,
			// 'curve_name' => 'secp256r1',
			'curve_name' => 'secp384r1',
			// 'curve_name' => 'secp521r1',
		];

		// Create the private and public key
		$key = openssl_pkey_new($config);
		openssl_pkey_export($key, $privateKeyPem);

		return $privateKeyPem;
	}

	/**
	 * Get our stored private PEM key (or regenerate it if it's expired)
	 * Extract the public key from the full/private key
	 * Build a JWK from the public key
	 *
	 * @return array
	 * @throws AppConfigTypeConflictException
	 */
	public function getJwk(): array {
		$myPemPrivateKey = $this->getMyPemPrivateKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$sslPublicKey = openssl_pkey_get_details($sslPrivateKey);
		return $this->getSigJwkFromSslKey($sslPublicKey);
		// $pubKeyPem = $sslPublicKey['key'];
		// return $this->getJwkFromPem($pubKeyPem)->jsonSerialize();
	}

	public function getSigJwkFromSslKey(array $sslKey): array {
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		return [
			'kty' => 'EC',
			'use' => 'sig',
			'kid' => 'sig_key_' . $pemPrivateKeyExpiresAt,
			'crv' => 'P-384',
			'x' => \rtrim(\strtr(\base64_encode($sslKey['ec']['x']), '+/', '-_'), '='),
			'y' => \rtrim(\strtr(\base64_encode($sslKey['ec']['y']), '+/', '-_'), '='),
		];
	}

	/**
	 * Build a JWK from a PEM (public) key
	 *
	 * @param string $pemKey
	 * @return KeyInterface
	 * @throws AppConfigTypeConflictException
	 */
	public function getJwkFromPem(string $pemKey): KeyInterface {
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		$options = [
			'use' => 'sig',
			'alg' => 'RS512',
			'kid' => 'sig_key_' . $pemPrivateKeyExpiresAt,
		];
		$keyFactory = new KeyFactory();
		return $keyFactory->createFromPem($pemKey, $options);
	}

	/**
	 * Create a JWT token signed with a given private SSL key
	 *
	 * @param array $payload
	 * @param \OpenSSLAsymmetricKey $key
	 * @param string $keyId
	 * @param string $alg
	 * @return string
	 */
	public function createJwt(array $payload, \OpenSSLAsymmetricKey $key, string $keyId, string $alg = 'RS512'): string {
		return JWT::encode($payload, $key, $alg, $keyId);
	}

	public function debug(): array {
		$myPemPrivateKey = $this->getMyPemPrivateKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pubKey = openssl_pkey_get_details($sslPrivateKey);
		$pubKeyPem = $pubKey['key'];

		$payload = ['lll' => 'aaa'];
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		$signedJwtToken = $this->createJwt($payload, $sslPrivateKey, 'sig_key_' . $pemPrivateKeyExpiresAt, 'RS512');

		// check content of JWT
		$rawJwks = ['keys' => [$this->getSigJwkFromSslKey($pubKey)]];
		$jwks = JWK::parseKeySet($rawJwks, 'RS512');
		$jwtPayload = JWT::decode($signedJwtToken, $jwks);
		$jwtPayloadArray = json_decode(json_encode($jwtPayload), true);

		return [
			'public_jwk' => $this->getSigJwkFromSslKey($pubKey),
			'public_pem' => $pubKeyPem,
			'private_pem' => $myPemPrivateKey,
			'payload' => $payload,
			'signed_jwt' => $signedJwtToken,
			'jwt_payload' => $jwtPayloadArray,
			'arrays_are_equal' => $payload === $jwtPayloadArray,
		];
	}

	public function debugRSA(): array {
		$myPemPrivateKey = $this->getMyPemPrivateKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pubKey = openssl_pkey_get_details($sslPrivateKey);
		$pubKeyPem = $pubKey['key'];

		$payload = ['lll' => 'aaa'];
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_PRIVATE_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		$signedJwtToken = $this->createJwt($payload, $sslPrivateKey, 'sig_key_' . $pemPrivateKeyExpiresAt);

		// check content of JWT
		$rawJwks = ['keys' => [$this->getJwkFromPem($pubKeyPem)->jsonSerialize()]];
		$jwks = JWK::parseKeySet($rawJwks, 'RS512');
		$jwtPayload = JWT::decode($signedJwtToken, $jwks);
		$jwtPayloadArray = json_decode(json_encode($jwtPayload), true);

		return [
			'public_jwk' => $this->getJwkFromPem($pubKeyPem)->jsonSerialize(),
			'public_pem' => $pubKeyPem,
			'private_pem' => $myPemPrivateKey,
			'payload' => $payload,
			'signed_jwt' => $signedJwtToken,
			'jwt_payload' => $jwtPayloadArray,
			'arrays_are_equal' => $payload === $jwtPayloadArray,
		];
	}
}
