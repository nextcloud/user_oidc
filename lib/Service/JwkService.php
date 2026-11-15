<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Exceptions\AppConfigTypeConflictException;

class JwkService {

	public const PEM_SIG_KEY_SETTINGS_KEY = 'pemSignatureKey';
	public const PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY = 'pemSignatureKeyCreatedAt';
	public const PEM_NEXT_SIG_KEY_SETTINGS_KEY = 'pemNextSignatureKey';
	public const PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY = 'pemNextSignatureKeyCreatedAt';
	public const PEM_SIG_KEY_EXPIRES_AFTER_SECONDS = 60 * 60;
	public const PEM_SIG_KEY_ALGORITHM = 'ES384';
	public const PEM_SIG_KEY_CURVE = 'P-384';

	public const PEM_ENC_KEY_SETTINGS_KEY = 'pemEncryptionKey';
	public const PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY = 'pemEncryptionKeyCreatedAt';
	public const PEM_ENC_KEY_EXPIRES_AFTER_SECONDS = 60 * 60;
	public const PEM_ENC_KEY_ALGORITHM = 'ECDH-ES+A192KW';
	public const PEM_ENC_KEY_CURVE = 'P-384';
	public const PEM_NEXT_ENC_KEY_SETTINGS_KEY = 'pemNextEncryptionKey';
	public const PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY = 'pemNextEncryptionKeyCreatedAt';
	// the retired encryption key is not published anymore but can still decrypt for this long after its retirement
	public const PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY = 'pemPreviousEncryptionKey';
	public const PEM_PREVIOUS_ENC_KEY_CREATED_AT_SETTINGS_KEY = 'pemPreviousEncryptionKeyCreatedAt';
	public const PEM_PREVIOUS_ENC_KEY_RETIRED_AT_SETTINGS_KEY = 'pemPreviousEncryptionKeyRetiredAt';
	public const PEM_PREVIOUS_ENC_KEY_GRACE_PERIOD_SECONDS = 60 * 60;

	private const SIGNATURE_KEY_SET = [
		'current' => self::PEM_SIG_KEY_SETTINGS_KEY,
		'currentCreatedAt' => self::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY,
		'next' => self::PEM_NEXT_SIG_KEY_SETTINGS_KEY,
		'nextCreatedAt' => self::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY,
		'expiresAfter' => self::PEM_SIG_KEY_EXPIRES_AFTER_SECONDS,
	];

	private const ENCRYPTION_KEY_SET = [
		'current' => self::PEM_ENC_KEY_SETTINGS_KEY,
		'currentCreatedAt' => self::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY,
		'next' => self::PEM_NEXT_ENC_KEY_SETTINGS_KEY,
		'nextCreatedAt' => self::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY,
		'expiresAfter' => self::PEM_ENC_KEY_EXPIRES_AFTER_SECONDS,
		'previous' => self::PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY,
		'previousCreatedAt' => self::PEM_PREVIOUS_ENC_KEY_CREATED_AT_SETTINGS_KEY,
		'previousRetiredAt' => self::PEM_PREVIOUS_ENC_KEY_RETIRED_AT_SETTINGS_KEY,
	];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Get our current signature PEM key after applying rotation if needed.
	 *
	 * @return string
	 * @throws AppConfigTypeConflictException
	 */
	public function getMyPemSignatureKey(): string {
		return $this->getCurrentPemKey(self::SIGNATURE_KEY_SET);
	}

	/**
	 * Get our current encryption PEM key after applying rotation if needed.
	 *
	 * @return string
	 * @throws AppConfigTypeConflictException
	 */
	public function getMyEncryptionKey(): string {
		return $this->getCurrentPemKey(self::ENCRYPTION_KEY_SET);
	}

	/**
	 * Get all the private encryption JWKs that may have been used by an IdP to encrypt a JWE for us:
	 * the current and next keys (both published) and the previous one during its grace period.
	 *
	 * @return list<array>
	 * @throws AppConfigTypeConflictException
	 */
	public function getDecryptionJwks(): array {
		$currentPem = $this->getMyEncryptionKey();
		$jwks = [
			$this->getJwkFromPem($currentPem, $this->getStoredInt(self::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY), true, true),
			$this->getJwkFromPem(
				$this->getStoredString(self::PEM_NEXT_ENC_KEY_SETTINGS_KEY),
				$this->getStoredInt(self::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY),
				true, true,
			),
		];

		$previousPem = $this->getStoredString(self::PEM_PREVIOUS_ENC_KEY_SETTINGS_KEY);
		$previousCreatedAt = $this->getStoredInt(self::PEM_PREVIOUS_ENC_KEY_CREATED_AT_SETTINGS_KEY);
		$previousRetiredAt = $this->getStoredInt(self::PEM_PREVIOUS_ENC_KEY_RETIRED_AT_SETTINGS_KEY);
		if ($previousPem !== '' && $previousCreatedAt !== 0
			&& time() <= $previousRetiredAt + self::PEM_PREVIOUS_ENC_KEY_GRACE_PERIOD_SECONDS) {
			$jwks[] = $this->getJwkFromPem($previousPem, $previousCreatedAt, true, true);
		}

		return $jwks;
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
		$signaturePem = $this->getMyPemSignatureKey();
		$encryptionPem = $this->getMyEncryptionKey();
		return [
			$this->getJwkFromPem($signaturePem, $this->getStoredInt(self::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY), false),
			$this->getJwkFromPem(
				$this->getStoredString(self::PEM_NEXT_SIG_KEY_SETTINGS_KEY),
				$this->getStoredInt(self::PEM_NEXT_SIG_KEY_CREATED_AT_SETTINGS_KEY),
				false,
			),
			$this->getJwkFromPem($encryptionPem, $this->getStoredInt(self::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY), true),
			$this->getJwkFromPem(
				$this->getStoredString(self::PEM_NEXT_ENC_KEY_SETTINGS_KEY),
				$this->getStoredInt(self::PEM_NEXT_ENC_KEY_CREATED_AT_SETTINGS_KEY),
				true,
			),
		];
	}

	public function getJwkFromSslKey(
		array $sslKeyDetails,
		bool $isEncryptionKey = false,
		bool $includePrivateKey = false,
		?int $keyCreatedAt = null,
	): array {
		$pemPrivateKeyCreatedAt = $keyCreatedAt ?? $this->appConfig->getAppValueInt(
			$isEncryptionKey ? self::PEM_ENC_KEY_CREATED_AT_SETTINGS_KEY : self::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY,
			lazy: true,
		);
		$jwk = [
			'kty' => 'EC',
			'use' => $isEncryptionKey ? 'enc' : 'sig',
			'kid' => ($isEncryptionKey ? 'enc' : 'sig') . '_key_' . $pemPrivateKeyCreatedAt,
			'crv' => $isEncryptionKey ? self::PEM_ENC_KEY_CURVE : self::PEM_SIG_KEY_CURVE,
			'x' => \rtrim(\strtr(\base64_encode($sslKeyDetails['ec']['x']), '+/', '-_'), '='),
			'y' => \rtrim(\strtr(\base64_encode($sslKeyDetails['ec']['y']), '+/', '-_'), '='),
			'alg' => $isEncryptionKey ? self::PEM_ENC_KEY_ALGORITHM : self::PEM_SIG_KEY_ALGORITHM,
		];
		if ($includePrivateKey) {
			$jwk['d'] = \rtrim(\strtr(\base64_encode($sslKeyDetails['ec']['d']), '+/', '-_'), '=');
		}
		return $jwk;
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
		// make sure we sign with the currently active key, while the next one is already pre-published
		$myPemPrivateKey = $this->getMyPemSignatureKey();
		$sslPrivateKey = openssl_pkey_get_private($myPemPrivateKey);
		$pemSignatureKeyCreatedAt = $this->appConfig->getAppValueInt(self::PEM_SIG_KEY_CREATED_AT_SETTINGS_KEY, lazy: true);

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

		return $this->createJwt($payload, $sslPrivateKey, 'sig_key_' . $pemSignatureKeyCreatedAt, self::PEM_SIG_KEY_ALGORITHM);
	}

	private function getJwkFromPem(string $pemKey, int $createdAt, bool $isEncryptionKey, bool $includePrivateKey = false): array {
		$sslKeyDetails = openssl_pkey_get_details(openssl_pkey_get_private($pemKey));
		return $this->getJwkFromSslKey($sslKeyDetails, $isEncryptionKey, $includePrivateKey, $createdAt);
	}

	private function getStoredString(string $key): string {
		return $this->appConfig->getAppValueString($key, lazy: true);
	}

	private function getStoredInt(string $key): int {
		return $this->appConfig->getAppValueInt($key, lazy: true);
	}

	private function getCurrentPemKey(array $keySet): string {
		$this->ensureKeysInitialized($keySet);
		$this->rotateKeysIfNeeded($keySet);

		return $this->getStoredString($keySet['current']);
	}

	private function ensureKeysInitialized(array $keySet): void {
		$currentPem = $this->getStoredString($keySet['current']);
		$currentCreatedAt = $this->getStoredInt($keySet['currentCreatedAt']);
		$nextPem = $this->getStoredString($keySet['next']);
		$nextCreatedAt = $this->getStoredInt($keySet['nextCreatedAt']);

		if ($currentPem === '' || $currentCreatedAt === 0) {
			if ($nextPem !== '' && $nextCreatedAt !== 0) {
				$this->storeKey($keySet['current'], $keySet['currentCreatedAt'], $nextPem, $nextCreatedAt);
				$currentCreatedAt = $nextCreatedAt;
				$nextPem = '';
				$nextCreatedAt = 0;
			} else {
				$currentCreatedAt = $this->getFreshKeyCreatedAt();
				$this->storeKey($keySet['current'], $keySet['currentCreatedAt'], $this->generatePemPrivateKey(), $currentCreatedAt);
			}
		}

		if ($nextPem === '' || $nextCreatedAt === 0) {
			$nextCreatedAt = $this->getFreshKeyCreatedAt($currentCreatedAt);
			$this->storeKey($keySet['next'], $keySet['nextCreatedAt'], $this->generatePemPrivateKey(), $nextCreatedAt);
		}
	}

	/**
	 * Promote the next key to current once both have been published for longer than the expiration delay,
	 * so the promoted key has been in the JWKS long enough for IdPs to have it in their cache.
	 */
	private function rotateKeysIfNeeded(array $keySet): void {
		$currentPem = $this->getStoredString($keySet['current']);
		$currentCreatedAt = $this->getStoredInt($keySet['currentCreatedAt']);
		$nextPem = $this->getStoredString($keySet['next']);
		$nextCreatedAt = $this->getStoredInt($keySet['nextCreatedAt']);

		if (!$this->shouldRotateKeys($currentCreatedAt, $nextCreatedAt, $keySet['expiresAfter']) || $nextPem === '') {
			return;
		}

		if (isset($keySet['previous'])) {
			$this->storeKey($keySet['previous'], $keySet['previousCreatedAt'], $currentPem, $currentCreatedAt);
			$this->appConfig->setAppValueInt($keySet['previousRetiredAt'], time(), lazy: true);
		}

		$this->storeKey($keySet['current'], $keySet['currentCreatedAt'], $nextPem, $nextCreatedAt);
		$this->storeKey(
			$keySet['next'],
			$keySet['nextCreatedAt'],
			$this->generatePemPrivateKey(),
			$this->getFreshKeyCreatedAt($nextCreatedAt),
		);
	}

	private function shouldRotateKeys(int $currentCreatedAt, int $nextCreatedAt, int $expiresAfter): bool {
		if ($currentCreatedAt === 0 || $nextCreatedAt === 0) {
			return false;
		}

		$now = time();
		return $now > $currentCreatedAt + $expiresAfter
			&& $now > $nextCreatedAt + $expiresAfter;
	}

	private function getFreshKeyCreatedAt(int ...$existingCreatedAt): int {
		$latestExistingCreatedAt = $existingCreatedAt === [] ? 0 : max($existingCreatedAt);
		return max(time(), $latestExistingCreatedAt + 1);
	}

	private function storeKey(string $keySetting, string $createdAtSetting, string $pemKey, int $createdAt): void {
		$this->appConfig->setAppValueString($keySetting, $pemKey, lazy: true, sensitive: true);
		$this->appConfig->setAppValueInt($createdAtSetting, $createdAt, lazy: true);
	}
}
