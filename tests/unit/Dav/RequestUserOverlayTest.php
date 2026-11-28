<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Dav\RequestUserOverlay;
use OCP\ISession;
use OCP\Session\Exceptions\SessionNotAvailableException;
use PHPUnit\Framework\TestCase;

class RequestUserOverlayTest extends TestCase {
	public function testUserIdStaysOffTheInnerSession(): void {
		$inner = new InMemorySession();
		$inner->set('LAST_ACTIVITY', 10);
		$overlay = new RequestUserOverlay($inner);

		$overlay->set('user_id', 'alice@example.test');
		$overlay->set('loginname', 'alice@example.test');

		$this->assertSame('alice@example.test', $overlay->get('user_id'));
		$this->assertSame('alice@example.test', $overlay->get('loginname'));
		$this->assertTrue($overlay->exists('user_id'));
		$this->assertArrayNotHasKey('user_id', $inner->data);
		$this->assertArrayNotHasKey('loginname', $inner->data);
		$this->assertNotContains('user_id', $inner->sets);
		$this->assertSame(10, $overlay->get('LAST_ACTIVITY'));
	}

	public function testAMaskedKeyDoesNotFallBackToTheBrowserUser(): void {
		$inner = new InMemorySession();
		$inner->set('user_id', 'browser-user');
		$overlay = new RequestUserOverlay($inner);

		$overlay->set('user_id', 'alice@example.test');
		$overlay->remove('user_id');

		$this->assertNull($overlay->get('user_id'));
		$this->assertFalse($overlay->exists('user_id'));
		$this->assertSame('browser-user', $inner->get('user_id'));
	}

	public function testOtherKeysPassThrough(): void {
		$inner = new InMemorySession();
		$overlay = new RequestUserOverlay($inner);

		$overlay->set('requesttoken', 'abc');

		$this->assertSame('abc', $inner->get('requesttoken'));
		$this->assertSame($inner, $overlay->inner());
	}
}

final class InMemorySession implements ISession {
	/** @var array<string, mixed> */
	public array $data = [];

	/** @var list<string> */
	public array $sets = [];

	public function set(string $key, $value) {
		$this->sets[] = $key;
		$this->data[$key] = $value;
	}

	public function get(string $key) {
		return $this->data[$key] ?? null;
	}

	public function exists(string $key): bool {
		return isset($this->data[$key]);
	}

	public function remove(string $key) {
		unset($this->data[$key]);
	}

	public function clear() {
		$this->data = [];
	}

	public function reopen(): bool {
		return false;
	}

	public function close() {
	}

	public function regenerateId(bool $deleteOldSession = true, bool $updateToken = false) {
	}

	public function getId(): string {
		throw new SessionNotAvailableException();
	}
}
