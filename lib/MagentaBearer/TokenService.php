<?php
/*
 * @copyright Copyright (c) 2021 Bernd Rederlechner <bernd.rederlechner@t-systems.com>
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

namespace OCA\UserOIDC\MagentaBearer;

use Psr\Log\LoggerInterface;
use OCP\AppFramework\Utility\ITimeFactory;

use Jose\Component\Core\JWK;
use Jose\Component\Core\Algorithm;
use Jose\Component\Core\AlgorithmManager;

use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\Compression\Deflate;
use Jose\Component\Encryption\JWEDecrypter;

use Jose\Component\Encryption\Algorithm\KeyEncryption\PBES2HS512A256KW;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\ECDHESA256KW;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;

use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\JWS;

class TokenService {

	/** @var LoggerInterface */
	private $logger;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var DiscoveryService */
	private $discoveryService;

	public function __construct(LoggerInterface $logger,
								ITimeFactory $timeFactory) {
		$this->logger = $logger;
		$this->timeFactory = $timeFactory;
		
		// The key encryption algorithm manager with the A256KW algorithm.
		$keyEncryptionAlgorithmManager = new AlgorithmManager([
			new PBES2HS512A256KW(),
			new RSAOAEP256(),
			new ECDHESA256KW() ]);
		
		// The content encryption algorithm manager with the A256CBC-HS256 algorithm.
		$contentEncryptionAlgorithmManager = new AlgorithmManager([
			new A256CBCHS512(),
		]);
		
		// The compression method manager with the DEF (Deflate) method.
		$compressionMethodManager = new CompressionMethodManager([
			new Deflate(),
		]);
		
		$signatureAlgorithmManager = new AlgorithmManager([
			new HS256(),
			new HS384(),
			new HS512(),
		]);

		// We instantiate our JWE Decrypter.
		$this->jweDecrypter = new JWEDecrypter(
			$keyEncryptionAlgorithmManager,
			$contentEncryptionAlgorithmManager,
			$compressionMethodManager
		);

		// We try to load the token.
		$this->encryptionSerializerManager = new \Jose\Component\Encryption\Serializer\JWESerializerManager([
			new \Jose\Component\Encryption\Serializer\CompactSerializer(),
		]);


		// We instantiate our JWS Verifier.
		$this->jwsVerifier = new JWSVerifier(
			$signatureAlgorithmManager
		);

		// The serializer manager. We only use the JWE Compact Serialization Mode.
		$this->serializerManager = new \Jose\Component\Signature\Serializer\JWSSerializerManager([
			new \Jose\Component\Signature\Serializer\CompactSerializer(),
		]);
	}
	
	/**
	 * Implement JOSE decryption for SAM3 tokens
	 */
	public function decryptToken(string $rawToken, string $decryptKey) : JWS {

		// web-token library does not like underscores in headers, so replace them with - (which is valid in JWT)
		$numSegments = substr_count($rawToken, '.') + 1;
		$this->logger->debug('Bearer access token(segments=' . $numSegments . ')=' . $rawToken);
		if ($numSegments > 3) {
			// trusted authenticator and myself share the client secret,
			// so use it is used for encrypted web tokens
			$clientSecret = new JWK([
				'kty' => 'oct',
				'k' => $decryptKey
			]);
			
			$jwe = $this->encryptionSerializerManager->unserialize($rawToken);
		
			// We decrypt the token. This method does NOT check the header.
			if ($this->jweDecrypter->decryptUsingKey($jwe, $clientSecret, 0)) {
				return $this->serializerManager->unserialize($jwe->getPayload());
			} else {
				throw new InvalidTokenException('Unknown bearer encryption format');
			}
		} else {
			return $this->serializerManager->unserialize($rawToken);
		}
	}

	/**
	 * Get claims (even before verification to access e.g. aud standard field ...)
	 * Transform them in a format compatible with id_token representation.
	 */
	public function decode(JWS $decodedToken) : object {
		$this->logger->debug("Telekom SAM3 access token: " . $decodedToken->getPayload());		
		$samContent = json_decode($decodedToken->getPayload(), false);

		// remap all the custom claims
		// adapt into OpenId id_token format (as far as possible)
        $claimArray = $samContent->{'urn:telekom.com:idm:at:attributes'};
		foreach ($claimArray as $claimKeyValue) {
			$samContent->{'urn:telekom.com:' . $claimKeyValue->name} = $claimKeyValue->value;
		}
        unset($samContent->{'urn:telekom.com:idm:at:attributes'});

		$this->logger->debug("Adapted OpenID-like token; " . json_encode($samContent));
		return $samContent;
	}


	public function verifySignature(JWS $decodedToken, string $signKey) {
		$accessSecret = new JWK([
			'kty' => 'oct',
			'k' => $signKey
		]); // TODO: take the additional access key secret from settings

		if (!$this->jwsVerifier->verifyWithKey($decodedToken, $accessSecret, 0)) {
			throw new SignatureException('Invalid Signature');
		}
	}

	public function verifyClaims(object $claims, array $audiences = [], int $leeway = 60) {
		$timestamp = $this->timeFactory->getTime();

		// Check the nbf if it is defined. This is the time that the
		// token can actually be used. If it's not yet that time, abort.
		if (isset($claims->nbf) && $claims->nbf > ($timestamp + $leeway)) {
			throw new InvalidTokenException(
				'Cannot handle token prior to ' . \date(DateTime::ISO8601, $claims->nbf)
			);
		}

		// Check that this token has been created before 'now'. This prevents
		// using tokens that have been created for later use (and haven't
		// correctly used the nbf claim).
		if (isset($claims->iat) && $claims->iat > ($timestamp + $leeway)) {
			throw new InvalidTokenException(
				'Cannot handle token prior to ' . \date(DateTime::ISO8601, $claims->iat)
			);
		}

		// Check if this token has expired.
		if (isset($claims->exp) && ($timestamp - $leeway) >= $claims->exp) {
			throw new InvalidTokenException('Expired token');
		}

		// Check target audience (if given)
		// Check if this token has expired.
		if (empty(array_intersect($claims->aud, $audiences))) {
			throw new InvalidTokenException('No acceptable audience in token.');
		}
	}
}
