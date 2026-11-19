<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Dav;

use OCP\IRequest;

// Drops the PHP session OC::initSession created when no session cookie was sent.
class EphemeralPhpSession {
	private static bool $discard = false;

	private static bool $shutdownRegistered = false;

	private static bool $restartPrepared = false;

	private static ?string $savedUseCookies = null;

	/** @var list<callable(): void> */
	private static array $beforeDiscard = [];

	public static function resetState(): void {
		self::$discard = false;
		self::$beforeDiscard = [];
		self::$restartPrepared = false;
		if (self::$savedUseCookies !== null && !headers_sent()) {
			try {
				\ini_set('session.use_cookies', self::$savedUseCookies);
			} catch (\Throwable) {
			}
		}
		self::$savedUseCookies = null;
	}

	public function armIfStatelessBearer(IRequest $request): void {
		$header = $request->getHeader('Authorization');
		$name = session_name();
		$cookie = ($name !== '') ? $request->getCookie($name) : null;
		self::$discard = self::shouldDiscard(
			self::isBearerAuthorization($header),
			is_string($cookie) && $cookie !== '',
		);
		if (self::$discard) {
			if ((string)\ini_get('session.save_handler') === 'user') {
				$this->allowRestartAfterHeaders();
			}
			$this->registerShutdown();
		}
	}

	// session_start in shutdown needs use_cookies off before headers go out.
	private function allowRestartAfterHeaders(): void {
		try {
			if (headers_sent()) {
				return;
			}
			if (self::$savedUseCookies === null) {
				self::$savedUseCookies = (string)\ini_get('session.use_cookies');
			}
			$set = \ini_set('session.use_cookies', '0');
			if ($set !== false && \ini_get('session.use_cookies') === '0') {
				self::$restartPrepared = true;
			}
		} catch (\Throwable) {
			self::$restartPrepared = false;
		}
	}

	public function isArmed(): bool {
		return self::$discard;
	}

	public function runBeforeDiscard(callable $callback): void {
		self::$beforeDiscard[] = $callback;
		$this->registerShutdown();
	}

	public static function isBearerAuthorization(string $header): bool {
		return preg_match('/^bearer\s+\S/i', $header) === 1;
	}

	public static function shouldDiscard(bool $isBearer, bool $requestBroughtSessionCookie): bool {
		return $isBearer && !$requestBroughtSessionCookie;
	}

	public static function sessionFile(string $sessionId, string $savePath, string $defaultDir): ?string {
		if (preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) !== 1) {
			return null;
		}
		$levels = 0;
		$dir = $savePath;
		if (str_contains($savePath, ';')) {
			$parts = explode(';', $savePath);
			$dir = (string)array_pop($parts);
			if (isset($parts[0]) && preg_match('/^\d+$/', $parts[0]) === 1) {
				$levels = (int)$parts[0];
			} else {
				return null;
			}
		}
		if ($levels < 0 || $levels > 3 || $levels > strlen($sessionId)) {
			return null;
		}
		if ($dir === '') {
			$dir = $defaultDir;
		}
		$path = rtrim($dir, '/');
		for ($i = 0; $i < $levels; $i++) {
			$path .= '/' . $sessionId[$i];
		}
		return $path . '/sess_' . $sessionId;
	}

	public function onShutdown(): void {
		$callbacks = self::$beforeDiscard;
		self::$beforeDiscard = [];
		foreach ($callbacks as $callback) {
			$callback();
		}
		if (!self::$discard) {
			return;
		}
		self::$discard = false;
		$this->discardNow();
	}

	// files unlink avoids session_destroy recreating the file. Redis and memcached delete in place.
	public function discardNow(): bool {
		if (session_status() === PHP_SESSION_DISABLED) {
			return false;
		}
		$id = session_id();
		if (!is_string($id) || $id === '') {
			return false;
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION = [];
			session_write_close();
		}

		$handler = (string)ini_get('session.save_handler');
		$savePath = (string)ini_get('session.save_path');
		if ($handler === 'files' || $handler === '' || $handler === 'redis' || $handler === 'memcached') {
			return self::deleteHandlerRecord($id, $handler, $savePath);
		}
		if ($handler === 'user' && self::$restartPrepared) {
			try {
				return self::destroyByRestart();
			} catch (\Throwable) {
				return false;
			}
		}
		return false;
	}

	public static function unlinkSessionFile(string $sessionId, string $savePath): bool {
		$file = self::sessionFile($sessionId, $savePath, sys_get_temp_dir());
		if ($file === null) {
			return false;
		}
		if (is_file($file)) {
			return unlink($file);
		}
		return true;
	}

	private static function destroyByRestart(): bool {
		if (!@session_start([
			'use_cookies' => false,
			'use_only_cookies' => true,
			'cache_limiter' => '',
		])) {
			return false;
		}
		$_SESSION = [];
		$destroyed = session_destroy();
		session_write_close();
		return $destroyed;
	}

	public static function deleteHandlerRecord(string $sessionId, string $handler, string $savePath): bool {
		if (preg_match('/^[A-Za-z0-9,-]+$/', $sessionId) !== 1) {
			return false;
		}
		if ($handler === 'files' || $handler === '') {
			return self::unlinkSessionFile($sessionId, $savePath);
		}
		if ($handler === 'redis') {
			return self::deleteRedisSession($sessionId, $savePath);
		}
		if ($handler === 'memcached') {
			return self::deleteMemcachedSession($sessionId, $savePath);
		}
		return false;
	}

	private static function deleteRedisSession(string $sessionId, string $savePath): bool {
		if (!class_exists(\Redis::class)) {
			return false;
		}
		$targets = preg_split('/,(?=tcp:|unix:)/', $savePath !== '' ? $savePath : 'tcp://127.0.0.1:6379') ?: [];
		$removed = false;
		foreach ($targets as $target) {
			$query = [];
			$base = $target;
			$qpos = strpos($target, '?');
			if ($qpos !== false) {
				parse_str(substr($target, $qpos + 1), $query);
				$base = substr($target, 0, $qpos);
			}
			$host = '127.0.0.1';
			$port = 6379;
			if (str_starts_with($base, 'unix://')) {
				$socket = substr($base, strlen('unix://'));
				if ($socket === '') {
					continue;
				}
				try {
					$redis = new \Redis();
					if (!$redis->connect($socket)) {
						continue;
					}
					$prefix = (string)ini_get('redis.session.prefix');
					if ($prefix === '') {
						$prefix = isset($query['prefix']) ? (string)$query['prefix'] : 'PHPREDIS_SESSION:';
					}
					$redis->del($prefix . $sessionId);
					$removed = $redis->exists($prefix . $sessionId) === 0;
				} catch (\Throwable) {
					continue;
				}
				continue;
			}
			$base = preg_replace('#^[a-z]+://#', '', $base) ?? $base;
			if (str_contains($base, ':')) {
				[$host, $portString] = explode(':', $base, 2);
				$port = (int)$portString;
			} elseif ($base !== '') {
				$host = $base;
			}
			$prefix = (string)ini_get('redis.session.prefix');
			if ($prefix === '') {
				$prefix = isset($query['prefix']) ? (string)$query['prefix'] : 'PHPREDIS_SESSION:';
			}
			try {
				$redis = new \Redis();
				if (!$redis->connect($host, $port, 0.5)) {
					continue;
				}
				if (isset($query['auth']) && (string)$query['auth'] !== '') {
					$redis->auth((string)$query['auth']);
				}
				if (isset($query['database'])) {
					$redis->select((int)$query['database']);
				}
				$redis->del($prefix . $sessionId);
				$removed = $redis->exists($prefix . $sessionId) === 0;
			} catch (\Throwable) {
				continue;
			}
		}
		return $removed;
	}

	private static function deleteMemcachedSession(string $sessionId, string $savePath): bool {
		if (!class_exists(\Memcached::class)) {
			return false;
		}
		$prefix = ini_get('memcached.sess_prefix');
		if (!is_string($prefix) || $prefix === '') {
			$prefix = 'memc.sess.key.';
		}
		$memcached = new \Memcached();
		foreach (explode(',', $savePath !== '' ? $savePath : '127.0.0.1:11211') as $server) {
			$server = trim($server);
			if ($server === '') {
				continue;
			}
			$host = $server;
			$port = 11211;
			if (str_contains($server, ':')) {
				[$host, $portString] = explode(':', $server, 2);
				$port = (int)$portString;
			}
			$memcached->addServer($host, $port);
		}
		$memcached->delete($prefix . $sessionId);
		$code = $memcached->getResultCode();
		return $code === \Memcached::RES_SUCCESS || $code === \Memcached::RES_NOTFOUND;
	}

	private function registerShutdown(): void {
		if (self::$shutdownRegistered) {
			return;
		}
		self::$shutdownRegistered = true;
		register_shutdown_function(static function (): void {
			(new self())->onShutdown();
		});
	}
}
