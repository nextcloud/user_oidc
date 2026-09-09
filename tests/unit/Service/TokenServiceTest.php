<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OC\Authentication\Token\IProvider;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Model\Token;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\TokenService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Focused tests for the cross-request refresh deduplication and proactive-refresh throttling
 * added to mitigate the refresh-token-rotation race (see issues #1449 / #1175).
 */
class TokenServiceTest extends TestCase {

	private const NOW = 1000;
	private const SESSION_TOKEN_KEY = 'user_oidc-user-token';
	private const REFRESH_RESULT_CACHE_KEY = 'refresh-result_sid';
	private const REFRESH_THROTTLE_CACHE_KEY = 'refresh-throttle_sid';

	/** @var HttpClientHelper|MockObject */
	private $clientService;
	/** @var ISession|MockObject */
	private $session;
	/** @var IUserSession|MockObject */
	private $userSession;
	/** @var IProvider|MockObject */
	private $tokenProvider;
	/** @var IConfig|MockObject */
	private $config;
	/** @var IAppConfig|MockObject */
	private $appConfig;
	/** @var LoggerInterface|MockObject */
	private $logger;
	/** @var ICrypto|MockObject */
	private $crypto;
	/** @var IRequest|MockObject */
	private $request;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var IEventDispatcher|MockObject */
	private $eventDispatcher;
	/** @var IAppManager|MockObject */
	private $appManager;
	/** @var DiscoveryService|MockObject */
	private $discoveryService;
	/** @var ProviderMapper|MockObject */
	private $providerMapper;
	/** @var ILockingProvider|MockObject */
	private $lockingProvider;
	/** @var ITimeFactory|MockObject */
	private $timeFactory;
	/** @var ICacheFactory|MockObject */
	private $cacheFactory;
	/** @var ICache|MockObject */
	private $cache;
	/** @var TokenService */
	private $tokenService;

	public function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(HttpClientHelper::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);

		$this->timeFactory->method('getTime')->willReturn(self::NOW);
		$this->session->method('getId')->willReturn('sid');
		$this->cacheFactory->method('createDistributed')->willReturn($this->cache);
		// transforming stub so tests can prove a value went through encryption before being cached
		$this->crypto->method('encrypt')->willReturnCallback(fn (string $plaintext): string => 'enc:' . $plaintext);

		$this->tokenService = new TokenService(
			$this->clientService,
			$this->session,
			$this->userSession,
			$this->tokenProvider,
			$this->config,
			$this->appConfig,
			$this->logger,
			$this->crypto,
			$this->request,
			$this->urlGenerator,
			$this->eventDispatcher,
			$this->appManager,
			$this->discoveryService,
			$this->providerMapper,
			$this->lockingProvider,
			$this->timeFactory,
			$this->cacheFactory,
		);
	}

	/**
	 * Build a serialized token as it is stored in the session / cache.
	 */
	private function tokenJson(
		string $accessToken,
		int $createdAt,
		int $expiresIn = 300,
		?string $refreshToken = 'refresh-token',
		?int $providerId = 1,
		?int $refreshExpiresIn = null,
	): string {
		return json_encode([
			'id_token' => null,
			'access_token' => $accessToken,
			'expires_in' => $expiresIn,
			'refresh_expires_in' => $refreshExpiresIn,
			'refresh_token' => $refreshToken,
			'created_at' => $createdAt,
			'provider_id' => $providerId,
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * Fix 1: when another request has already refreshed the token (visible via the shared
	 * cache), getToken() must adopt that result instead of refreshing again — so it never
	 * presents the already-rotated refresh token to the IdP.
	 */
	public function testGetTokenAdoptsCachedRefreshedTokenWithoutContactingIdp(): void {
		// session holds a still-valid but expiring token (created at 800, half-life passed at 950, now 1000)
		$this->session->method('get')->willReturn($this->tokenJson('old-access', 800));
		// the shared cache holds a fresh token refreshed by a concurrent request
		$this->cache->method('get')->willReturn('encrypted');
		$this->crypto->method('decrypt')->willReturn($this->tokenJson('fresh-access', self::NOW));

		// the IdP must NOT be contacted
		$this->clientService->expects($this->never())->method('post');

		$result = $this->tokenService->getToken(refreshIfExpired: true, refreshIfExpiring: true);

		Assert::assertNotNull($result);
		Assert::assertSame('fresh-access', $result->getAccessToken());
	}

	/**
	 * Fix 2: while a proactive refresh was attempted recently (throttle marker present) and no
	 * fresh token is cached, getToken() must return the current still-valid token unchanged
	 * instead of hammering the IdP on every request.
	 */
	public function testGetTokenThrottlesProactiveRefresh(): void {
		$this->session->method('get')->willReturn($this->tokenJson('old-access', 800));
		$this->cache->method('get')->willReturnCallback(function (string $key) {
			// no fresh token cached, but a proactive refresh was attempted recently
			return $key === self::REFRESH_THROTTLE_CACHE_KEY ? '1' : null;
		});

		$this->clientService->expects($this->never())->method('post');

		$result = $this->tokenService->getToken(refreshIfExpired: true, refreshIfExpiring: true);

		Assert::assertNotNull($result);
		Assert::assertSame('old-access', $result->getAccessToken());
	}

	/**
	 * When not throttled and nothing is cached, the proactive refresh must still happen: the IdP
	 * is contacted once, the throttle marker is recorded, and the new token is returned.
	 */
	public function testGetTokenProactivelyRefreshesAndRecordsAttempt(): void {
		$this->session->method('get')->willReturn($this->tokenJson('old-access', 800));
		// nothing cached and not throttled (every cache read misses)
		$this->cache->method('get')->willReturn(null);

		$setEntries = [];
		$this->cache->method('set')->willReturnCallback(function ($key, $value, $ttl) use (&$setEntries): bool {
			$setEntries[$key] = $value;
			return true;
		});

		$provider = new Provider();
		$provider->setClientId('client-id');
		$provider->setClientSecret('');
		$this->providerMapper->method('getProvider')->willReturn($provider);
		$this->discoveryService->method('obtainDiscovery')->willReturn(['token_endpoint' => 'https://idp.example/token']);

		$this->clientService->expects($this->once())
			->method('post')
			->willReturn($this->tokenJson('refreshed-access', self::NOW, refreshToken: 'rotated-refresh-token'));

		$result = $this->tokenService->getToken(refreshIfExpired: true, refreshIfExpiring: true);

		Assert::assertNotNull($result);
		Assert::assertSame('refreshed-access', $result->getAccessToken());
		// a proactive refresh attempt must be recorded for throttling
		Assert::assertArrayHasKey(self::REFRESH_THROTTLE_CACHE_KEY, $setEntries);
		// the refreshed token must be encrypted, then mirrored into the shared cache
		Assert::assertArrayHasKey(self::REFRESH_RESULT_CACHE_KEY, $setEntries);
		$cachedBlob = $setEntries[self::REFRESH_RESULT_CACHE_KEY];
		Assert::assertStringStartsWith('enc:', $cachedBlob, 'the cached token must go through ICrypto::encrypt');
		$cachedToken = json_decode(substr($cachedBlob, 4), true, 512, JSON_THROW_ON_ERROR);
		Assert::assertSame('refreshed-access', $cachedToken['access_token']);
		Assert::assertSame('rotated-refresh-token', $cachedToken['refresh_token']);
	}

	/**
	 * Fix 1 (concurrency core): a request that reaches refresh() after losing the race must, once
	 * it holds the lock, detect the token already refreshed by the winner via the shared cache and
	 * reuse it — never POSTing a rotated refresh token to the IdP.
	 */
	public function testRefreshReusesTokenAlreadyRefreshedByConcurrentRequest(): void {
		$this->cache->method('get')->willReturn('encrypted');
		$this->crypto->method('decrypt')->willReturn($this->tokenJson('fresh-access', self::NOW));

		$this->clientService->expects($this->never())->method('post');

		$staleToken = new Token(json_decode($this->tokenJson('old-access', 800), true, 512, JSON_THROW_ON_ERROR), $this->timeFactory);
		$result = $this->tokenService->refresh($staleToken);

		Assert::assertSame('fresh-access', $result->getAccessToken());
	}

	/**
	 * RFC 6749 section 6: a provider MAY omit the refresh token in a refresh response, in which
	 * case the previous refresh token remains valid. It must be carried over into the stored
	 * token, otherwise non-rotating providers lose refreshability after the first refresh.
	 */
	public function testRefreshKeepsPreviousRefreshTokenWhenResponseOmitsIt(): void {
		$this->session->method('get')->willReturn($this->tokenJson('old-access', 800, refreshToken: 'original-refresh-token'));
		$this->cache->method('get')->willReturn(null);

		$provider = new Provider();
		$provider->setClientId('client-id');
		$provider->setClientSecret('');
		$this->providerMapper->method('getProvider')->willReturn($provider);
		$this->discoveryService->method('obtainDiscovery')->willReturn(['token_endpoint' => 'https://idp.example/token']);

		// the refresh response contains no refresh_token (non-rotating provider)
		$this->clientService->expects($this->once())
			->method('post')
			->willReturn($this->tokenJson('refreshed-access', self::NOW, refreshToken: null));

		$result = $this->tokenService->getToken(refreshIfExpired: true, refreshIfExpiring: true);

		Assert::assertNotNull($result);
		Assert::assertSame('refreshed-access', $result->getAccessToken());
		Assert::assertSame('original-refresh-token', $result->getRefreshToken(), 'the previous refresh token must be kept when the response omits one');
	}

	/**
	 * #1449: a background/XHR request whose token cannot be refreshed must NOT terminate the
	 * Nextcloud session — doing so makes the web UI detect a dead session and force-reload the
	 * page, losing unsaved work. Only a real top-level navigation may log out and redirect.
	 */
	public function testReauthenticateKeepsSessionOnBackgroundRequest(): void {
		// not a top-level HTML navigation (e.g. a notifications / heartbeat poll)
		$this->request->method('getMethod')->willReturn('POST');

		$this->userSession->expects($this->never())->method('logout');

		$this->tokenService->reauthenticate(1);
	}

	/**
	 * Expired-token path: if another request already refreshed (visible in the shared cache),
	 * adopt it without contacting the IdP — recovering the session instead of bouncing the user.
	 */
	public function testGetTokenAdoptsCachedTokenWhenSessionTokenExpired(): void {
		// session token fully expired (created at 600, expired at 900, now 1000)
		$this->session->method('get')->willReturn($this->tokenJson('old-access', 600));
		$this->cache->method('get')->willReturn('encrypted');
		$this->crypto->method('decrypt')->willReturn($this->tokenJson('fresh-access', self::NOW));

		$this->clientService->expects($this->never())->method('post');

		$result = $this->tokenService->getToken(refreshIfExpired: true, refreshIfExpiring: true);

		Assert::assertNotNull($result);
		Assert::assertSame('fresh-access', $result->getAccessToken());
	}

	/**
	 * Expired-token path: a refresh attempted very recently is throttled, so a dead refresh token
	 * is not POSTed to the IdP on every background request. The (still-expired) token is returned
	 * and the caller keeps the session alive rather than bouncing the user.
	 */
	public function testGetTokenThrottlesRefreshOfExpiredToken(): void {
		$this->session->method('get')->willReturn($this->tokenJson('old-access', 600));
		$this->cache->method('get')->willReturnCallback(function (string $key) {
			// nothing cached to adopt, but a refresh was attempted recently
			return $key === self::REFRESH_THROTTLE_CACHE_KEY ? '1' : null;
		});

		$this->clientService->expects($this->never())->method('post');

		$result = $this->tokenService->getToken(refreshIfExpired: true, refreshIfExpiring: true);

		Assert::assertNotNull($result);
		Assert::assertSame('old-access', $result->getAccessToken());
	}
}
