<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\UserOIDC\Dav\DavRequestUser;
use OCA\UserOIDC\Dav\RequestUserOverlay;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Session\Exceptions\SessionNotAvailableException;
use PHPUnit\Framework\TestCase;

class DavRequestUserTest extends TestCase {
	public function testBindPublishesTheUidWithoutWritingThePhpSession(): void {
		$inner = new InMemorySessionForDav();
		$inner->set('user_id', 'browser-user');
		$session = new SwappableUserSession($inner);
		$user = $this->user('alice@example.test');

		$overlaid = $this->binder()->bind($session, $user);

		$this->assertTrue($overlaid);
		$this->assertInstanceOf(RequestUserOverlay::class, $session->getSession());
		$this->assertSame($user, $session->getUser());
		$this->assertTrue($session->isLoggedIn());
		$this->assertSame('alice@example.test', $session->getSession()->get('user_id'));
		$this->assertSame('alice@example.test', $session->getSession()->get('loginname'));
		$this->assertSame('browser-user', $inner->get('user_id'));
		$this->assertArrayNotHasKey('loginname', $inner->data);
	}

	public function testBindDoesNotReplaceAnOverlayThatIsAlreadyInstalled(): void {
		$inner = new InMemorySessionForDav();
		$session = new SwappableUserSession(new RequestUserOverlay($inner));
		$user = $this->user('alice@example.test');

		$this->assertTrue($this->binder()->bind($session, $user));
		$this->assertSame('alice@example.test', $session->getSession()->get('user_id'));
		$this->assertArrayNotHasKey('user_id', $inner->data);
	}

	public function testWithoutSetSessionTheKeyIsWrittenAndThenReleased(): void {
		$inner = new InMemorySessionForDav();
		$session = new PlainUserSession($inner);
		$user = $this->user('alice@example.test');
		$binder = $this->binder();

		$this->assertFalse($binder->bind($session, $user));
		$this->assertSame('alice@example.test', $inner->get('user_id'));

		$binder->releaseFallback();

		$this->assertNull($inner->get('user_id'));
		$this->assertNull($session->getUser());
		$binder->releaseFallback();
	}

	private function binder(): DavRequestUser {
		return new DavRequestUser();
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}

final class InMemorySessionForDav implements ISession {
	/** @var array<string, mixed> */
	public array $data = [];

	public function set(string $key, $value) {
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

final class SwappableUserSession implements IUserSession {
	public ?IUser $user = null;

	public ?string $loginName = null;

	public function __construct(
		public ISession $session,
	) {
	}

	public function getSession(): ISession {
		return $this->session;
	}

	public function setSession(ISession $session): void {
		$this->session->close();
		$this->session = $session;
		$this->user = null;
	}

	public function setUser($user) {
		if ($user === null) {
			$this->session->remove('user_id');
			$this->user = null;
			return;
		}
		$this->session->set('user_id', $user->getUID());
		$this->user = $user;
	}

	public function setLoginName(?string $loginName): void {
		if ($loginName === null) {
			$this->session->remove('loginname');
		} else {
			$this->session->set('loginname', $loginName);
		}
		$this->loginName = $loginName;
	}

	public function login($uid, $password) {
		return false;
	}

	public function logout() {
	}

	public function setVolatileActiveUser(?IUser $user): void {
		$this->user = $user;
	}

	public function getUser() {
		return $this->user;
	}

	public function isLoggedIn() {
		return $this->user !== null;
	}

	public function getImpersonatingUserID(): ?string {
		return null;
	}

	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
	}
}

final class PlainUserSession implements IUserSession {
	public ?IUser $user = null;

	public function __construct(
		public InMemorySessionForDav $session,
	) {
	}

	public function setUser($user) {
		if ($user === null) {
			$this->session->remove('user_id');
			$this->user = null;
			return;
		}
		$this->session->set('user_id', $user->getUID());
		$this->user = $user;
	}

	public function setLoginName(?string $loginName): void {
		if ($loginName === null) {
			$this->session->remove('loginname');
		} else {
			$this->session->set('loginname', $loginName);
		}
	}

	public function login($uid, $password) {
		return false;
	}

	public function logout() {
	}

	public function setVolatileActiveUser(?IUser $user): void {
		$this->user = $user;
	}

	public function getUser() {
		return $this->user;
	}

	public function isLoggedIn() {
		return $this->user !== null;
	}

	public function getImpersonatingUserID(): ?string {
		return null;
	}

	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
	}
}
