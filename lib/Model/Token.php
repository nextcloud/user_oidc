<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Model;

use JsonSerializable;

class Token implements JsonSerializable {

	private ?string $idToken;
	private string $accessToken;
	private int $expiresIn;
	private ?int $refreshExpiresIn;
	private ?string $refreshToken;
	private int $createdAt;
	private ?int $providerId;

	public function __construct(array $tokenData) {
		$this->idToken = $tokenData['id_token'] ?? null;
		$this->accessToken = $tokenData['access_token'];
		$this->expiresIn = $tokenData['expires_in'];
		$this->refreshExpiresIn = $tokenData['refresh_expires_in'] ?? null;
		$this->refreshToken = $tokenData['refresh_token'] ?? null;
		$this->createdAt = $tokenData['created_at'] ?? time();
		$this->providerId = $tokenData['provider_id'] ?? null;
	}

	public function getAccessToken(): string {
		return $this->accessToken;
	}

	public function getIdToken(): ?string {
		return $this->idToken;
	}

	public function getExpiresIn(): int {
		return $this->expiresIn;
	}

	public function getExpiresInFromNow(): int {
		$expiresAt = $this->createdAt + $this->expiresIn;
		return $expiresAt - time();
	}

	public function getRefreshExpiresIn(): ?int {
		return $this->refreshExpiresIn;
	}

	public function getRefreshExpiresInFromNow(): int {
		// if there is no refresh_expires_in, we assume the refresh token never expires
		// so we don't need getRefreshExpiresInFromNow
		if ($this->refreshExpiresIn === null) {
			return 0;
		}
		$refreshExpiresAt = $this->createdAt + $this->refreshExpiresIn;
		return $refreshExpiresAt - time();
	}

	public function getRefreshToken(): ?string {
		return $this->refreshToken;
	}

	public function getProviderId(): ?int {
		return $this->providerId;
	}

	public function isExpired(): bool {
		return time() > ($this->createdAt + $this->expiresIn);
	}

	public function isExpiring(): bool {
		return time() > ($this->createdAt + (int)($this->expiresIn / 2));
	}

	public function refreshIsExpired(): bool {
		// if there is no refresh_expires_in, we assume the refresh token never expires
		if ($this->refreshExpiresIn === null) {
			return false;
		}
		return time() > ($this->createdAt + $this->refreshExpiresIn);
	}

	public function refreshIsExpiring(): bool {
		// if there is no refresh_expires_in, we assume the refresh token never expires
		if ($this->refreshExpiresIn === null) {
			return false;
		}
		return time() > ($this->createdAt + (int)($this->refreshExpiresIn / 2));
	}

	public function getCreatedAt() {
		return $this->createdAt;
	}

	public function jsonSerialize(): array {
		return [
			'id_token' => $this->idToken,
			'access_token' => $this->accessToken,
			'expires_in' => $this->expiresIn,
			'refresh_expires_in' => $this->refreshExpiresIn,
			'refresh_token' => $this->refreshToken,
			'created_at' => $this->createdAt,
			'provider_id' => $this->providerId,
		];
	}
}
