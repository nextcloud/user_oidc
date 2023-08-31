<?php
/*
 * @copyright Copyright (c) 2021 T-Systems International
 *
 * @author Bernd Rederlechner <bernd.rederlechner@t-systems.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 */

declare(strict_types=1);

use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\LocalIdService;
use OCA\UserOIDC\Service\ProvisioningEventService;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Db\SessionMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\UserOIDC\Event\UserAccountChangeEvent;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OC\AppFramework\Bootstrap\Coordinator;
use OC\Authentication\Token\IProvider;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IDBConnection;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use OCP\ILogger; // deprecated!
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OC\Security\Crypto;

use OCP\AppFramework\App;

use PHPUnit\Framework\MockObject\MockObject;
use OCA\UserOIDC\BaseTest\OpenidTokenTestCase;

class ProvisioningEventServiceTest extends OpenidTokenTestCase {
	/**
	 * Set up needed system and app configurations
	 */
	protected function getConfigSetup() :MockObject {
		$config = $this->getMockForAbstractClass(IConfig::class);
	
		$config->expects($this->any())
			->method("getSystemValue")
			->with($this->logicalOr($this->equalTo('user_oidc'), $this->equalTo('secret')))
			->willReturnCallback(function ($key, $default) {
				if ($key == 'user_oidc') {
					return [
						'auto_provisioning' => true,
					];
				} elseif ($key == 'secret') {
					return "Streng_geheim";
				}
			});
		return $config;
	}
	
	/**
	 * Prepare a proper session as if the handshake with an
	 * OpenID authenticator entity has already been done.
	 */
	protected function getOidSessionSetup() :MockObject {
		$session = $this->getMockForAbstractClass(ISession::class);

		$session->expects($this->any())
			->method('get')
			->willReturn($this->returnCallback(function ($key) {
				$values = [
					'oidc.state' => $this->getOidTestState(),
					'oidc.providerid' => $this->getProviderId(),
					'oidc.nonce' => $this->getOidNonce(),
					'oidc.redirect' => 'https://welcome.to.magenta'
				];

				return $values[$key] ? $values[$key] : "some_" . $key;
			}));
		$this->sessionMapper = $this->getMockBuilder(SessionMapper::class)
								->setConstructorArgs([ $this->getMockForAbstractClass(IDBConnection::class) ])
								->getMock();
		$this->sessionMapper->expects($this->any())
							->method('createSession');

		return $session;
	}

	/**
	 * Prepare a proper session as if the handshake with an
	 * OpenID authenticator entity has already been done.
	 */
	protected function getProviderSetup() :MockObject {
		$provider = $this->getMockBuilder(Provider::class)
			->addMethods(['getClientId', 'getClientSecret'])
			->getMock();
		$provider->expects($this->any())
				->method('getClientId')
				->willReturn($this->getOidClientId());
		$provider->expects($this->once())
				->method('getClientSecret')
				->willReturn($this->crypto->encrypt($this->getOidClientSecret()));
		$this->providerMapper->expects($this->once())
				->method('getProvider')
				->with($this->equalTo($this->getProviderId()))
				->willReturn($provider);

		return $provider;
	}


	/**
	 * Prepare a proper mapping configuration for the provider
	 */
	protected function getProviderServiceSetup() :MockObject {
		$providerService = $this->getMockBuilder(ProviderService::class)
							->setConstructorArgs([ $this->config, $this->providerMapper])
							->getMock();
		$providerService->expects($this->any())
				->method('getSetting')
				->with($this->equalTo($this->getProviderId()), $this->logicalOr(
					$this->equalTo(ProviderService::SETTING_MAPPING_UID),
					$this->equalTo(ProviderService::SETTING_MAPPING_DISPLAYNAME),
					$this->equalTo(ProviderService::SETTING_MAPPING_QUOTA),
					$this->equalTo(ProviderService::SETTING_MAPPING_EMAIL),
					$this->anything()))
				->will($this->returnCallback(function ($providerid, $key, $default):string {
					$values = [
						ProviderService::SETTING_MAPPING_UID => 'sub',
						ProviderService::SETTING_MAPPING_DISPLAYNAME => 'urn:custom.com:displayname',
						ProviderService::SETTING_MAPPING_QUOTA => 'urn:custom.com:f556',
						ProviderService::SETTING_MAPPING_EMAIL => 'urn:custom.com:mainEmail'
					];
					return $values[$key];
				}));
		return $providerService;
	}

	/**
	 * Prepare a proper session as if the handshake with an
	 * OpenID authenticator entity has already been done.
	 */
	protected function getUserManagerSetup() :MockObject {
		$userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->user = $this->getMockForAbstractClass(IUser::class);
		$this->user->expects($this->any())
				->method("canChangeAvatar")
				->willReturn(false);

		return $userManager;
	}


	/**
	 * This is the standard execution sequence until provisoning
	 * is triggered in LoginController, set up with an artificial
	 * yet valid OpenID token.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->app = new App(Application::APP_ID);
		$this->config = $this->getConfigSetup();
		$this->crypto = $this->getMockBuilder(Crypto::class)
								->setConstructorArgs([ $this->config ])
								->getMock();

		$this->request = $this->getMockForAbstractClass(IRequest::class);
		$this->request->expects($this->once())
						->method('getServerProtocol')
						->willReturn('https');
		$this->providerMapper = $this->getMockBuilder(ProviderMapper::class)
							->setConstructorArgs([ $this->getMockForAbstractClass(IDBConnection::class) ])
							->getMock();
		$this->provider = $this->getProviderSetup();
		$this->providerService = $this->getProviderServiceSetup();
		$this->localIdService = $this->getMockBuilder(LocalIdService::class)
							->setConstructorArgs([ $this->providerService,
								$this->providerMapper])
							->getMock();
		$this->userMapper = $this->getMockBuilder(UserMapper::class)
							->setConstructorArgs([ $this->getMockForAbstractClass(IDBConnection::class),
								$this->localIdService ])
							->getMock();
		$this->discoveryService = $this->getMockBuilder(DiscoveryService::class)
							->setConstructorArgs([ $this->app->getContainer()->get(LoggerInterface::class),
								$this->getMockForAbstractClass(IClientService::class),
								$this->providerService,
								$this->app->getContainer()->get(ICacheFactory::class) ])
							->getMock();
		$this->discoveryService->expects($this->once())
							->method('obtainDiscovery')
							->willReturn(array( 'token_endpoint' => 'https://whatever.to.discover/token',
								'issuer' => 'https:\/\/accounts.login00.custom.de' ));
		$this->discoveryService->expects($this->once())
							->method('obtainJWK')
							->willReturn($this->getOidPublicServerKey());
		$this->session = $this->getOidSessionSetup();
		$this->client = $this->getMockForAbstractClass(IClient::class);
		$this->response = $this->getMockForAbstractClass(IResponse::class);
		//$this->usersession = $this->getMockForAbstractClass(IUserSession::class);
		$this->usersession = $this->getMockBuilder(IUserSession::class)
							->disableOriginalConstructor()
							->onlyMethods(['setUser', 'login', 'logout', 'getUser', 'isLoggedIn',
								'getImpersonatingUserID', 'setImpersonatingUserID'])
							->addMethods(['completeLogin', 'createSessionToken', 'createRememberMeToken'])
							->getMock();
		$this->usermanager = $this->getUserManagerSetup();
		$this->groupmanager = $this->getMockForAbstractClass(IGroupManager::class);
		$this->dispatcher = $this->app->getContainer()->get(IEventDispatcher::class);

		$this->provisioningService = new ProvisioningEventService(
								$this->app->getContainer()->get(LocalIdService::class),
								$this->providerService,
								$this->userMapper,
								$this->usermanager,
								$this->groupmanager,
								$this->dispatcher,
								$this->app->getContainer()->get(ILogger::class));
		// here is where the token magic comes in
		$this->token = array( 'id_token' =>
							$this->createSignToken($this->getRealOidClaims(),
													$this->getOidServerKey()));
		$this->tokenResponse = $this->getMockForAbstractClass(IResponse::class);
		$this->tokenResponse->expects($this->once())
							->method("getBody")
							->willReturn(json_encode($this->token));
		
		// mock token retrieval
		$this->client = $this->getMockForAbstractClass(IClient::class);
		$this->client->expects($this->once())
				   ->method("post")
				   ->with($this->equalTo('https://whatever.to.discover/token'), $this->arrayHasKey('body'))
				   ->willReturn($this->tokenResponse);
		$this->clientService = $this->getMockForAbstractClass(IClientService::class);
		$this->clientService->expects($this->once())
							   ->method("newClient")
							   ->willReturn($this->client);
		$this->registrationContext =
						$this->app->getContainer()->get(Coordinator::class)->getRegistrationContext();
		$this->loginController = new LoginController($this->request,
							$this->providerMapper,
							$this->providerService,
							$this->discoveryService,
							$this->app->getContainer()->get(LdapService::class),
							$this->app->getContainer()->get(ISecureRandom::class),
							$this->session,
							$this->clientService,
							$this->app->getContainer()->get(IUrlGenerator::class),
							$this->usersession,
							$this->usermanager,
							$this->app->getContainer()->get(ITimeFactory::class),
							$this->dispatcher,
							$this->config,
							$this->app->getContainer()->get(IProvider::class),
							$this->sessionMapper,
							$this->provisioningService,
							$this->app->getContainer()->get(IL10N::class),
							$this->app->getContainer()->get(ILogger::class),
							$this->crypto);

		$this->attributeListener = null;
		$this->accountListener = null;
	}

	/**
	 * Seems like the event dispatcher requires explicit unregistering
	 */
	public function tearDown(): void {
		parent::tearDown();
		if ($this->accountListener != null) {
			$this->dispatcher->removeListener(UserAccountChangeEvent::class, $this->accountListener);
		}
		if ($this->attributeListener != null) {
			$this->dispatcher->removeListener(AttributeMappedEvent::class, $this->attributeListener);
		}
	}

	protected function mockAssertLoginSuccess() {
		$this->usermanager->expects($this->once())
				->method('get')
				->willReturn($this->user);
		$this->session->expects($this->once())
					->method("set")
					->with($this->equalTo('oidc.id_token'), $this->anything());
		$this->usersession->expects($this->once())
					->method("setUser")
					->with($this->equalTo($this->user));
		$this->usersession->expects($this->once())
					->method("completeLogin")
					->with($this->anything(), $this->anything());
		$this->usersession->expects($this->once())
					->method("createSessionToken");
		$this->usersession->expects($this->once())
					->method("createRememberMeToken");
	}

	protected function assertLoginRedirect($result) {
		$this->assertInstanceOf(RedirectResponse::class,
			$result, "LoginController->code() did not end with success redirect: Status: " .
						strval($result->getStatus() . ' ' . json_encode($result->getThrottleMetadata())));
	}

	protected function assertLogin403($result) {
		$this->assertInstanceOf(TemplateResponse::class,
			$result, "LoginController->code() did not end with 403 Forbidden: Actual status: " .
						strval($result->getStatus() . ' ' . json_encode($result->getThrottleMetadata())));
	}

	/**
	 * Test with the default mapping, no mapping by attribute events
	 * provisioning with successful result.
	 */
	public function testNoMap_AccessOk() {
		$this->mockAssertLoginSuccess();
		$this->accountListener = function (Event $event) :void {
			$this->assertInstanceOf(UserAccountChangeEvent::class, $event);
			$this->assertEquals('jgyros', $event->getUid());
			$this->assertEquals('Jonny G', $event->getDisplayname());
			$this->assertEquals('jonny.gyuris@x.y.de', $event->getMainEmail());
			$this->assertNull($event->getQuota());
			$event->setResult(true, 'ok', null);
		};

		$this->dispatcher->addListener(UserAccountChangeEvent::class, $this->accountListener);
		$result = $this->loginController->code($this->getOidTestState(), $this->getOidTestCode(), '');

		$this->assertLoginRedirect($result);
		$this->assertEquals('https://welcome.to.magenta', $result->getRedirectURL());
	}

	/**
	 * Test uid event mapping and successful login.
	 */
	public function testUidMapEvent_AccessOk() {
		$this->mockAssertLoginSuccess();
		$this->attributeListener = function (Event $event): void {
			if ($event instanceof AttributeMappedEvent &&
				$event->getAttribute() == ProviderService::SETTING_MAPPING_UID) {
				//$defaultUID = $event->getValue();
				$event->setValue("991500000001234");
			}
		};
		$this->accountListener = function (Event $event) :void {
			$this->assertInstanceOf(UserAccountChangeEvent::class, $event);
			$this->assertEquals('991500000001234', $event->getUid());
			$this->assertEquals('Jonny G', $event->getDisplayname());
			$this->assertEquals('jonny.gyuris@x.y.de', $event->getMainEmail());
			$this->assertNull($event->getQuota());
			$event->setResult(true, 'ok', "https://welcome.to.darkside");
		};

		$this->dispatcher->addListener(AttributeMappedEvent::class, $this->attributeListener);
		$this->dispatcher->addListener(UserAccountChangeEvent::class, $this->accountListener);
		$result = $this->loginController->code($this->getOidTestState(), $this->getOidTestCode(), '');

		$this->assertLoginRedirect($result);
		$this->assertEquals('https://welcome.to.magenta', $result->getRedirectURL());
	}



	/**
	 * Test displayname set by event scheduling and negative result
	 */
	public function testDisplaynameMapEvent_NOk_NoRedirect() {
		$this->attributeListener = function (Event $event): void {
			if ($event instanceof AttributeMappedEvent &&
				$event->getAttribute() == ProviderService::SETTING_MAPPING_DISPLAYNAME) {
				$event->setValue("Lisa, Mona");
			}
		};
		$this->accountListener = function (Event $event) :void {
			$this->assertInstanceOf(UserAccountChangeEvent::class, $event);
			$this->assertEquals('jgyros', $event->getUid());
			$this->assertEquals('Lisa, Mona', $event->getDisplayname());
			$this->assertEquals('jonny.gyuris@x.y.de', $event->getMainEmail());
			$this->assertNull($event->getQuota());
			$event->setResult(false, 'not an original', null);
		};
		$this->dispatcher->addListener(AttributeMappedEvent::class, $this->attributeListener);
		$this->dispatcher->addListener(UserAccountChangeEvent::class, $this->accountListener);
		$result = $this->loginController->code($this->getOidTestState(), $this->getOidTestCode(), '');

		$this->assertLogin403($result);
	}

	public function testMainEmailMap_Nok_Redirect() {
		$this->attributeListener = function (Event $event): void {
			if ($event instanceof AttributeMappedEvent &&
				$event->getAttribute() == ProviderService::SETTING_MAPPING_EMAIL) {
				//$defaultUID = $event->getValue();
				$event->setValue("mona.lisa@louvre.fr");
			}
		};
		$this->accountListener = function (Event $event) :void {
			$this->assertInstanceOf(UserAccountChangeEvent::class, $event);
			$this->assertEquals('jgyros', $event->getUid());
			$this->assertEquals('Jonny G', $event->getDisplayname());
			$this->assertEquals('mona.lisa@louvre.fr', $event->getMainEmail());
			$this->assertNull($event->getQuota());
			$event->setResult(false, 'under restoration', 'https://welcome.to.louvre');
		};
		$this->dispatcher->addListener(AttributeMappedEvent::class, $this->attributeListener);
		$this->dispatcher->addListener(UserAccountChangeEvent::class, $this->accountListener);
		$result = $this->loginController->code($this->getOidTestState(), $this->getOidTestCode(), '');

		$this->assertLoginRedirect($result);
		$this->assertEquals('https://welcome.to.louvre', $result->getRedirectURL());
	}

	public function testDisplaynameUidQuotaMapped_AccessOK() {
		$this->mockAssertLoginSuccess();
		$this->attributeListener = function (Event $event): void {
			if ($event instanceof AttributeMappedEvent) {
				if ($event->getAttribute() == ProviderService::SETTING_MAPPING_UID) {
					$event->setValue("99887766553");
				} elseif ($event->getAttribute() == ProviderService::SETTING_MAPPING_DISPLAYNAME) {
					$event->setValue("Lisa, Mona");
				} elseif ($event->getAttribute() == ProviderService::SETTING_MAPPING_QUOTA) {
					$event->setValue("5 TB");
				}
			}
		};
		$this->accountListener = function (Event $event) :void {
			$this->assertInstanceOf(UserAccountChangeEvent::class, $event);
			$this->assertEquals('99887766553', $event->getUid());
			$this->assertEquals('Lisa, Mona', $event->getDisplayname());
			$this->assertEquals('jonny.gyuris@x.y.de', $event->getMainEmail());
			$this->assertEquals('5 TB', $event->getQuota());
			$event->setResult(true, 'ok', "https://welcome.to.louvre");
		};

		$this->dispatcher->addListener(AttributeMappedEvent::class, $this->attributeListener);
		$this->dispatcher->addListener(UserAccountChangeEvent::class, $this->accountListener);
		$result = $this->loginController->code($this->getOidTestState(), $this->getOidTestCode(), '');

		$this->assertLoginRedirect($result);
		$this->assertEquals('https://welcome.to.magenta', $result->getRedirectURL());
	}
}
