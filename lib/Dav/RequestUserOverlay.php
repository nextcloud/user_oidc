<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCP\ISession;

// user_id and loginname stay on this object so the PHP session file keeps no DAV bearer identity.
class RequestUserOverlay implements ISession {
	private const MEMORY_KEYS = ['user_id', 'loginname'];

	/** @var array<string, mixed> */
	private array $memory = [];

	/** @var array<string, true> */
	private array $masked = [];

	public function __construct(
		private ISession $inner,
	) {
	}

	public function inner(): ISession {
		return $this->inner;
	}

	public function set(string $key, $value) {
		if ($this->isMemoryKey($key)) {
			$this->masked[$key] = true;
			$this->memory[$key] = $value;
			return;
		}
		$this->inner->set($key, $value);
	}

	public function get(string $key) {
		if (isset($this->masked[$key])) {
			return $this->memory[$key] ?? null;
		}
		return $this->inner->get($key);
	}

	public function exists(string $key): bool {
		if (isset($this->masked[$key])) {
			return ($this->memory[$key] ?? null) !== null;
		}
		return $this->inner->exists($key);
	}

	public function remove(string $key) {
		if ($this->isMemoryKey($key)) {
			$this->masked[$key] = true;
			$this->memory[$key] = null;
			return;
		}
		$this->inner->remove($key);
	}

	public function clear() {
		$this->memory = [];
		$this->masked = [];
		$this->inner->clear();
	}

	public function reopen(): bool {
		return $this->inner->reopen();
	}

	public function close() {
		$this->inner->close();
	}

	public function regenerateId(bool $deleteOldSession = true, bool $updateToken = false) {
		$this->inner->regenerateId($deleteOldSession, $updateToken);
	}

	public function getId(): string {
		return $this->inner->getId();
	}

	private function isMemoryKey(string $key): bool {
		return in_array($key, self::MEMORY_KEYS, true);
	}
}
