<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

require_once __DIR__ . '/../../vendor/autoload.php';

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use Strobotti\JWK\Key\KeyInterface;
use Strobotti\JWK\KeyFactory;

class JwkService {

	public const PEM_SIG_KEY_SETTINGS_KEY = 'pemSignatureKey';
	public const PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY = 'pemSignatureKeyExpiresAt';
	public const PEM_SIG_KEY_EXPIRES_IN_SECONDS = 60 * 60;

	public const PEM_ENC_KEY_SETTINGS_KEY = 'pemEncryptionKey';
	public const PEM_ENC_KEY_EXPIRES_AT_SETTINGS_KEY = 'pemEncryptionKeyExpiresAt';
	public const PEM_ENC_KEY_EXPIRES_IN_SECONDS = 60 * 60;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Get our stored signature PEM key (or regenerate it if it's expired)
	 *
	 * @param bool $refresh
	 * @return string
	 * @throws AppConfigTypeConflictException
	 */
	public function getMyPemSignatureKey(bool $refresh = true): string {
		$pemSignatureKey = $this->appConfig->getAppValueString(self::PEM_SIG_KEY_SETTINGS_KEY, lazy: true);
		$pemSignatureKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);

		if ($pemSignatureKey === '' || $pemSignatureKeyExpiresAt === 0 || ($refresh && time() > $pemSignatureKeyExpiresAt)) {
			$pemSignatureKey = $this->generatePemPrivateKey();
			// store the key
			$this->appConfig->setAppValueString(self::PEM_SIG_KEY_SETTINGS_KEY, $pemSignatureKey, lazy: true);
			$this->appConfig->setAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, time() + self::PEM_SIG_KEY_EXPIRES_IN_SECONDS, lazy: true);
		}
		return $pemSignatureKey;
	}

	/**
	 * Get our stored encryption PEM key (or regenerate it if it's expired)
	 *
	 * @param bool $refresh
	 * @return string
	 * @throws AppConfigTypeConflictException
	 */
	public function getMyEncryptionKey(bool $refresh = true): string {
		$pemEncryptionKey = $this->appConfig->getAppValueString(self::PEM_ENC_KEY_SETTINGS_KEY, lazy: true);
		$pemEncryptionKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_ENC_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);

		if ($pemEncryptionKey === '' || $pemEncryptionKeyExpiresAt === 0 || ($refresh && time() > $pemEncryptionKeyExpiresAt)) {
			$pemEncryptionKey = $this->generatePemPrivateKey();
			// store the key
			$this->appConfig->setAppValueString(self::PEM_ENC_KEY_SETTINGS_KEY, $pemEncryptionKey, lazy: true);
			$this->appConfig->setAppValueInt(self::PEM_ENC_KEY_EXPIRES_AT_SETTINGS_KEY, time() + self::PEM_ENC_KEY_EXPIRES_IN_SECONDS, lazy: true);
		}
		return $pemEncryptionKey;
	}

	/**
	 * Generate a new full/private key and return it in PEM format
	 *
	 * @return string
	 */
	public function generatePemPrivateKey(): string {
		$config = [
			// 'digest_alg' => 'ES384',
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
	public function getJwks(): array {
		// we don't refresh here to make sure the IdP will get the key that was used to sign the client assertion
		$myPemSignatureKey = $this->getMyPemSignatureKey(false);
		$sslSignatureKey = openssl_pkey_get_private($myPemSignatureKey);
		$sslSignatureKeyDetails = openssl_pkey_get_details($sslSignatureKey);

		$myPemEncryptionKey = $this->getMyEncryptionKey(true);
		$sslEncryptionKey = openssl_pkey_get_private($myPemEncryptionKey);
		$sslEncryptionKeyDetails = openssl_pkey_get_details($sslEncryptionKey);
		return [
			$this->getJwkFromSslKey($sslSignatureKeyDetails),
			$this->getJwkFromSslKey($sslEncryptionKeyDetails, isEncryptionKey: true),
		];
		// $pubKeyPem = $sslPublicKey['key'];
		// return $this->getJwkFromPem($pubKeyPem)->jsonSerialize();
	}

	public function getJwkFromSslKey(array $sslKeyDetails, bool $isEncryptionKey = false): array {
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		$jwk = [
			'kty' => 'EC',
			'use' => $isEncryptionKey ? 'enc' : 'sig',
			'kid' => ($isEncryptionKey ? 'enc' : 'sig') . '_key_' . $pemPrivateKeyExpiresAt,
			'crv' => 'P-384',
			'x' => \rtrim(\strtr(\base64_encode($sslKeyDetails['ec']['x']), '+/', '-_'), '='),
			'y' => \rtrim(\strtr(\base64_encode($sslKeyDetails['ec']['y']), '+/', '-_'), '='),
			'alg' => $isEncryptionKey ? 'ECDH-ES+A192KW' : 'ES384',
		];
		return $jwk;
	}

	/**
	 * Build a JWK from a PEM (public) key
	 *
	 * @param string $pemKey
	 * @return KeyInterface
	 * @throws AppConfigTypeConflictException
	 */
	public function getJwkFromPem(string $pemKey): KeyInterface {
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
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

	public function generateClientAssertion(Provider $provider, string $discoveryIssuer, ?string $code = null): string {
		// we refresh (if needed) here to make sure we use a key that will be served to the IdP in a few seconds
		$myPemPrivateKey = $this->getMyPemSignatureKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);

		$payload = [
			'sub' => $provider->getClientId(),
			'aud' => $discoveryIssuer,
			'iss' => $provider->getClientId(),
			'iat' => time(),
			'exp' => time() + 60,
			'jti' => \bin2hex(\random_bytes(16)),
		];

		if ($code !== null) {
			$payload['code'] = $code;
		}

		return $this->createJwt($payload, $sslPrivateKey, 'sig_key_' . $pemPrivateKeyExpiresAt, 'ES384');
	}

	public function debug(): array {
		$myPemPrivateKey = $this->getMyPemSignatureKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pubKey = openssl_pkey_get_details($sslPrivateKey);
		$pubKeyPem = $pubKey['key'];

		$payload = ['lll' => 'aaa'];
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
		$signedJwtToken = $this->createJwt($payload, $sslPrivateKey, 'sig_key_' . $pemPrivateKeyExpiresAt, 'ES384');

		// check content of JWT
		$rawJwks = ['keys' => [$this->getJwkFromSslKey($pubKey)]];
		$jwks = JWK::parseKeySet($rawJwks, 'ES384');
		$jwtPayload = JWT::decode($signedJwtToken, $jwks);
		$jwtPayloadArray = json_decode(json_encode($jwtPayload), true);

		// check header of JWT
		$jwtParts = explode('.', $signedJwtToken, 3);
		$jwtHeader = json_decode(JWT::urlsafeB64Decode($jwtParts[0]), true);

		return [
			'public_jwk' => $this->getJwkFromSslKey($pubKey),
			'public_pem' => $pubKeyPem,
			'private_pem' => $myPemPrivateKey,
			'initial_payload' => $payload,
			'signed_jwt' => $signedJwtToken,
			'jwt_header' => $jwtHeader,
			'decoded_jwt_payload' => $jwtPayloadArray,
			'arrays_are_equal' => $payload === $jwtPayloadArray,
		];
	}

	public function debugRSA(): array {
		$myPemPrivateKey = $this->getMyPemSignatureKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pubKey = openssl_pkey_get_details($sslPrivateKey);
		$pubKeyPem = $pubKey['key'];

		$payload = ['lll' => 'aaa'];
		$pemPrivateKeyExpiresAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_EXPIRES_AT_SETTINGS_KEY, lazy: true);
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
