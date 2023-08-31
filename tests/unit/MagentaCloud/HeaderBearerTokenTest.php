<?php
/*
 * @copyright Copyright (c) 2021 T-Systems International
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

use OCA\UserOIDC\BaseTest\BearerTokenTestCase;
use OCA\UserOIDC\MagentaBearer\MBackend;

use Psr\Log\LoggerInterface;
use OCP\IRequest;
use OCP\ISession;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;

use OCP\EventDispatcher\IEventDispatcher;

use OCA\UserOIDC\AppInfo\Application;

//use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\SignatureException;
use OCA\UserOIDC\Service\InvalidTokenException;

use OCA\UserOIDC\MagentaBearer\TokenService;
use OCA\UserOIDC\Service\ProvisioningEventService;

use Base64Url\Base64Url;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class HeaderBearerTokenTest extends BearerTokenTestCase {

	/**
	 * @var ProviderService
	 */
	private $provider;

	/**
	 * @var MBackend
	 */
	private $backend;

	/**
	 * @var IConfig;
	 */
	private $config;

	public function setUp(): void {
		parent::setUp();

		$app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->requestMock = $this->createMock(IRequest::class);

		$this->config = $this->createMock(IConfig::class);
		$this->config->expects(self::any())
			->method('getAppValue')
			->willReturnMap([
				[Application::APP_ID, 'provider-2-' . ProviderService::SETTING_MAPPING_UID, 'sub', 'uid'],
				[Application::APP_ID, 'provider-2-' . ProviderService::SETTING_MAPPING_DISPLAYNAME, 'urn:telekom.com:displayname', 'dn'],
				[Application::APP_ID, 'provider-2-' . ProviderService::SETTING_MAPPING_EMAIL, 'urn:telekom.com:mainEmail', 'mail'],
				[Application::APP_ID, 'provider-2-' . ProviderService::SETTING_MAPPING_QUOTA, 'quota', '1g'],
				[Application::APP_ID, 'provider-2-' . ProviderService::SETTING_UNIQUE_UID, '0', '0'],
			]);

        $this->b64BearerToken = $this->getTestBearerSecret();

		$this->providerMapper = $this->createMock(ProviderMapper::class);
        $provider1 = $this->getMockBuilder(Provider::class)
                            ->addMethods(['getId', 'getIdentifier', 'getClientId', 'getClientSecret', 
                            'getBearerSecret'])->getMock();
        $provider1->expects(self::any())->method('getId')->willReturn(1);
        $provider1->expects(self::any())->method('getIdentifier')->willReturn('Fraesbook');
        $provider1->expects(self::any())->method('getClientId')->willReturn('FraesRein1');
        $provider1->expects(self::any())->method('getClientSecret')->willReturn("client****");
        $provider1->expects(self::any())->method('getBearerSecret')->willReturn("xx***");

        $provider2 = $this->getMockBuilder(Provider::class)
                            ->addMethods(['getId', 'getIdentifier', 'getClientId', 'getClientSecret', 
                            'getBearerSecret', 'getDiscoveryEndpoint'])->getMock();
        $provider2->expects(self::any())->method('getId')->willReturn(2);
        $provider2->expects(self::any())->method('getIdentifier')->willReturn('Telekom');
        $provider2->expects(self::any())->method('getClientId')->willReturn('10TVL0SAM30000004901NEXTMAGENTACLOUDTEST');
        $provider2->expects(self::any())->method('getClientSecret')->willReturn("client****");
        $provider2->expects(self::any())->method('getBearerSecret')->willReturn($this->getTestBearerSecret());
        $provider2->expects(self::any())->method('getDiscoveryEndpoint')->willReturn('https://accounts.login00.idm.ver.sul.t-online.de/.well-known/openid-configuration');

		$this->providerMapper->expects(self::any())
			->method('getProviders')
			->willReturn([ $provider1, $provider2 ]);

		$this->providerService = $this->createMock(ProviderService::class);
		$this->providerService->expects($this->any())
                                ->method('getSetting')
                                ->with( $this->anything(), $this->logicalOr($this->equalTo(ProviderService::SETTING_CHECK_BEARER), 
                                        $this->equalTo(ProviderService::SETTING_MAPPING_UID)))
                                ->willReturnCallback(function ($id, $field, $default) :string {
                                    if ($field === ProviderService::SETTING_MAPPING_UID) {
                                        return 'sub';
                                    } elseif ($field === ProviderService::SETTING_CHECK_BEARER) {
                                        return '1'; 
                                    } else {
                                        return '';
                                    }
                                });


		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('1200490100000000100XXXXX');
		$user->expects($this->any())
			->method('getDisplayName')
			->willReturn('nmc01');
		$user->expects($this->any())
			->method('getEMailAddress')
			->willReturn('nmc01@ver.sul.t-online.de');

        $userManager = $this->createMock(IUserManager::class);
        $userManager->expects($this->any())
                    ->method('get')
                    ->willReturn($user);

		$provisioningService = $this->createMock(ProvisioningEventService::class);
		$provisioningService->expects($this->any())
			->method("provisionUser")
			->willReturn($user);

		$this->backend = new MBackend($app->getContainer()->get(IConfig::class),
                                $app->getContainer()->get(UserMapper::class),
                                $app->getContainer()->get(LoggerInterface::class),
								$this->requestMock,
                                $app->getContainer()->get(ISession::class),
                                $app->getContainer()->get(IURLGenerator::class),
                                $app->getContainer()->get(IEventDispatcher::class),
                                $app->getContainer()->get(DiscoveryService::class),
								$this->providerMapper,
								$this->providerService,
                                $userManager,
								$app->getContainer()->get(TokenService::class),
								$provisioningService);
	}

	public function testValidSignature() {
		$testtoken = $this->setupSignedToken($this->getRealExampleClaims(), $this->b64BearerToken);
		$this->requestMock->expects($this->any())
						->method('getHeader')
						->with($this->equalTo(Application::OIDC_API_REQ_HEADER))
						->willReturn("Bearer " . $testtoken);

		$this->assertTrue($this->backend->isSessionActive());
		$this->assertEquals('1200490100000000100XXXXX', $this->backend->getCurrentUserId());
	}

	public function testInvalidSignature() {
		$testtoken = $this->setupSignedToken($this->getRealExampleClaims(), $this->b64BearerToken);
		$invalidSignToken = mb_substr($testtoken, 0, -1); // shorten sign to invalidate
		$this->requestMock->expects($this->any())
						->method('getHeader')
						->with($this->equalTo(Application::OIDC_API_REQ_HEADER))
						->willReturn("Bearer " . $invalidSignToken);

		$this->assertTrue($this->backend->isSessionActive());
		$this->assertEquals('', $this->backend->getCurrentUserId());
	}

	public function testEncryptedValidSignature() {
		$testtoken = $this->setupSignEncryptToken($this->getRealExampleClaims(), $this->b64BearerToken);
		$this->requestMock->expects($this->any())
						->method('getHeader')
						->with($this->equalTo(Application::OIDC_API_REQ_HEADER))
						->willReturn("Bearer " . $testtoken);

		$this->assertTrue($this->backend->isSessionActive());
		$this->assertEquals('1200490100000000100XXXXX', $this->backend->getCurrentUserId());
	}

	public function testEncryptedInvalidSignature() {
		$invalidEncToken = $this->setupSignEncryptToken($this->getRealExampleClaims(), 
                                                    $this->b64BearerToken, true);
		$this->requestMock->expects($this->any())
						->method('getHeader')
						->with($this->equalTo(Application::OIDC_API_REQ_HEADER))
						->willReturn("Bearer " . $invalidEncToken);

		$this->assertTrue($this->backend->isSessionActive());
		$this->assertEquals('', $this->backend->getCurrentUserId());
	}

	public const ENCRPYT1_SIGN_TOKEN = "eyJwMnMiOiI4VzhYY21iaHJPSSIsInAyYyI6MTAwMCwiY3R5IjoiSldUIiwiZW5jIjoiQTI1NkNCQy1IUzUxMiIsImFsZyI6IlBCRVMyLUhTNTEyK0EyNTZLVyJ9.5bA_ctLbQOnMojJW3MPo83AIvCAu3MpmaaD7j2GzqBv5_-D4w69ONqcPEsc6LYMG9B-rw3HDXng4Mqye4KqpW70ECpf9HXV6.6zl4Zqp4wbcO_AqqmpA3sQ.y7dHcwxXveYkuh4UaqHhE4nvP_avZsxaf7aAbnJdDHHKbBKvEKKqHkPg593i14ypWuRHd2i9Opsuyppfxx9Hw7C7N7LJ8UCTYMihHqlJkHecB08xgJ3ciE0L2Qtvg9hfxQbHNVV4p1_KL3ubAXt9ovwDCOJvN6PXyixUDtYYF1D_Km7Ze1ptUNbwS2H4vf-MKHwwrm5uhTvXOppGNO-0tYnIMOZ8BkiTtrrlO6IQbRcC4EMw74PzbFsQXY9u1xsNZ9IOrzbBl_EyPBLr5ool1BGlvNog4XFsHLgxUa5cjIcZVRMgZSLWdToTiXYFAWdO6fbQrRWT8ERRDWjiDxJEaPlfI_61G5NzJN2NKnSAY7fR8i3Rfs_JoF1TtpR5dGU28Lk1vcLjKYBLqp2hjW97QsANVgmalkkJMUpiAvNN48ZSCK9T3vTfiH7unFRNWvTKvZXyHIkYQPZ0-b3Z9s5oLMx93Snvcq9jQVKA1dWU_bEUIOnwP65ADU_FIkYB8gsZXp5Za3HrK63u03Lij6rwkJpEPbwcnxhBkMhtKOOwQVZm1ZBf_lVyn39MFXmLN_gDD052vFpxl1NnG0KEg8XJQ_usE9e64q7W6IG4gRm9NYG6rdeik6Dm45K8fA4oUiyjdgHjveR6GW8uXQR-tWXf3IC-_2jws2PJ31acdoEbDU30XlVeCqENW-ylPJ10rP28XxboQVJMRrzMiEzu39IH3c02czHh81U09TREVsO2S8CCQcahboaplDg9kpr1UZpsRrjg40bEtdm2cKubTbczGiXiF7sI0qE-kHm0aiK5c6mO8fHETMCmvh2vhxcYo_T6q7VklbwiZVbn47z-oriEDyPlLrB_PzYR6fNRbtObttj0CHRgf-NI69RU2pAGxujSi2lEhNkG-CAFNfASKm8uSUCg8UPr7v38c5vr4IuYC1gYjxgebXIh0EFX4G8jZM6ljPSzmMFDyErWJQ5OrtJjuKrUa96Yp3oOZTemtCwc--mrDXmpwVlaBMCuuJDz6zucxwSeVK0mP0t56zHeK59jxz0OfV62TrcVeZaLqSl3o-pVsY5KrLxL1qf2QIry-uy_c1zi9AuZnSbH3t1RvmyG5-QIh5WSPOLXG9ivuHKAdQTvBnchXWfkUVkoPYuPFyBydlPAhpRQyBLHboqdT6lIdoQ5lBRI8vsGb9wQVSQx08hbpEFOPMe-SJqzjZp36sUurJrgj_ethbIWkTSe_HPkcvBv8X0kyvhnyTKYJoroE5HDM0dtgFW8xK8NmOZOuREzJW5fpqzJML8iY0p1IX3bvGrCeVMEJtM0T6KSJFdPHBAzkWNNMBUc2jhuxa6B2cSaMz60bwSCw8n5NWz8wkXUFJJkHKEnK8tFbtOQXHeGG48k7Wl6kgrQkAFAHZqQt9gRDdmGcYAYHVK7cESjABV9LWQIQYy0eyveU0sWE5sYXKCwsk8rLiKt5GmZlRQ0rOltuFXRTu_EZYuqR0DCRXrjQWVN1zLTy0LMqAvDR-PJcFtekbT9CXLEW6M6GHzJhYfNyMc_cPitG8QwS5EWGzJjQIiNsJBRyV7cPlHeMhKzDtEk3DR3l-qQJa9-54RQB-kStJjB0AAZ21ku7eBS6orT0lljj935eghlHxAzyr1fvlDjIpHc--ob_7DOPc9sBGqcwdYoZ28zD1d02rpJujOwTe4zgll4vffJ_aFP8hm19pmroCwFsZPWIK6GN_cllJaxnllkJ_9c-7eBj1rKkNX0DLyNwKoMYttugeQFWAxaaqWhoOpQXnRHaVt5hTzoexi5C2j_aVBUAzyMPZtvuYgY1uc8zeKt5X8rAy3Y7WqYeOy8Q6IezVyTE6p0kzYgzUT1Vg2XZEr7dBgNkv8ySfYQNG5d8_PtvBHX-SOy25rtes7oUHHgZx0AkpomhNGSwfrW4dyIWCa6j5qUexqs3TPip_FAJwdW38OnyfPQ5SHLTt8D6OCOLN70MdbPpeoFkGnx1oj1Xjx_UW8mtueWAkxidv6Lamf_D5j8sJvkksne8Nos2YvGNkaGZwQK8YfjvPP-VVdukLMqoloovOuvgxLVLSvnDYcRRjfwAdiKwFNGdMbdV5LwfAzVAlncyWPJso3Lk9fPYd88YW8e6o7xiboiushcbDQU0ZN_Zh9YGk-8R4VnvAuI3yWxLrBB8NFUwKYkNBupVWrxRHJbJEebsLv9r_PZstBHHfMFpcQYX05NYfQiezhQ9l-aseC9Ay4FLbcxyXkIiPEBfiwZESqQbYoL3OeBQYzsV8AFe4GVdUUwPCuPjKR52UlkPiUJthxGkLFfcEPbqfX_lByN5YZRMSruOt6yKysbBIw0gcC6n7wuA_URaFNSPfyHe6nqAtveh1YjZpwZszAERyk2ziFXKFYFppdjMPvxF37uWoH_BEpv9Bs7yaxPRK7pfniS105RBsDFS093-3sUYM6W7IrmPfKAe71OtdWtQQqQKOAX3WGFShCIKyz-aOJWJPRG35Q2DOGu0nehFetGVsSnt-ehmru-Zuv4IanlF0_3SjQ7l7l6gg3Sfyy6sN8SVvxTtw4jLkaAM6cpmVMQVP8uQeJ9IFSHyq1kFceQcguh5tbwMknJzcMNzmZ9zEOG4ifyk9zmeulX9Rtf3lIXIOU-1lEs5bVm42eg1IKpxaY8PeTrT4qvPIyVkOprpKGIAcGyD0tP11vvDCvbltEWBo72gdbtD9tUdUPK0XRD_TgEPy2YU6I6BsKBStd40Fk6nOCGrq-mjYmH6OK3JUF3EVV7E0fEg7BgnYPLxcla0l7H6LpY4sqmFwapDqknjhgbqK0dyZDGWEPJ7Ph_5K6BazKuV_1bf6ZFOuRbm72cmT6vAJM8BhihAdTQt92QbTPikjLS2he5AfSV1ieDgLT26dsLNuLkyExyBqUGkrFoojh4fvW9K-wDKtgvQwCYZYABlC9JY72gtpaV2OV2UrB4aXuJX6n1NNXaSzpPqSupAIGK3Gaw39yrzBgBjTYAe0nnRu10BO7-gNRvKGIMCBTa7c-c0o0eNGe81xv1w8_-6auoKZYS8rzXQ8T6XLUjC1mRZD_cGxnfEra2G96-Cqm9WZO5hVX5fpXZhybz7neyGKlUKZG_An-jGmc9j_m03-5EEOfKAXJNlmOT1IynNVudtzTTrh8O5Dp4nD6fKsyOrg-6yRePCiP4FeItLCH6uVLWWdR65WZzklQuPrBELg58OzIsaBuKCKNjODSA4dGVE4JurhmgnnSmaqz2z6s0Zd1gXERebk_1WEmkWd03jO7dXMk3hOM9zV9BrZALOAll3GsvCqgh9kfouX-3ZNSNO7Lah6ecLD_zK228ap6r1MeY2VK-PiHUEnH58jh2HuutZB1Ge0GVvsYBue_r0FjGVNh6a9XYwIaf1Um2Z81WgHpWHZ-pLVZlkbN1vxgqLNBpjDy6UWpPJzOUv829C31WID92Wa6XPsfq6sIvYRUEx03DE2sbXKjUNX2t8InuLCgC6_wmq-GOoZ5vLKt1KHMicJUM9YFZYYKd-7c25X6DLplAnP-Hw_URgRINQdD8kOWzZ_70SiEq0om6OWniva6czSiwrcml_UBDA5Xr8pNtSWqtNbHh1LJzJenVIZl9gPLRs_o-OxB9gylqk7HwQZgKPCbvccYyh162Iy_Kg2j07hnDuoiUyZ93o9x_3Asf8Ms_E_ov6CqpFgKICX6rEE0oOgFO_pKvwtNH8fF-uNkVGKQwNYX6S33SlWh_pULYLSl-YrXVP0hLLmGlunnOGXUIVTXjQcc6AheR8Dmg9jDIefpgHMH6hegAnoZL0_AVuG-yd9LSRSh2qH_rABtJHTOx-0qQ6yYnrzHcMuvatCwDuIePK5DcxBj8KhKq9F4y_i5Ym9drIskRvAzwygZuIIuT3uyXl5nI6YE_jd6F9w4PZ7SkOs9JvfCnt-Wm7UKI6dxLnCRoTarUwop1wDZ77-rRwYoo5zYwF73BragZBZuWNB8ImLlktcAyCBF6P2_F2j4jvnQNLShYZ5HsJKsJNljjIiKYEAeJ2ScT2tjPSfMsdssWQPPByDgwnWtGpx2z6JTFGLUHaj_WbQe3hciyl7jGM2U1JrA610-Jb0X_OiGslZuYBasmPkEXFbDhZy_QZ4Pjs4RddBqrS15-H4FphxsB4knYHtfAzvJno80QmR69zvIfBSIScEx48foHjbeObNpW51IGbg2-yhssa9YtLpjpafnc1-yJ5xj6tJWYZcpskhgADRQvoxF8Xa7BE8o0D9-I7r2Yp0wMfYrbX8NCTBUWczxBZt2juBIERwgjHZzphIGVXNJ6ARm9F12UMf2OwUEk56J6SiSfB1ho7EDdARwj6Nfkm1LjpYLDhii-IRVJUN8tphw6SHVJBbMucYsXsL8viafUwdh7MbBwLKOPgZM4H9BqWFePgEglf7nzrALd2WV40tOai-sm4e4UCKh9bQ1qNw-uHQLP81NNzMA.bMWJdVmxAg2RZm7NE9wTz4H4LwjDb21tFV8hGtTKGFI";

    public function testEncryptedRealSignature1() {
		$this->requestMock->expects($this->any())
						->method('getHeader')
						->with($this->equalTo(Application::OIDC_API_REQ_HEADER))
						->willReturn("Bearer " . self::ENCRPYT1_SIGN_TOKEN);

		$this->assertTrue($this->backend->isSessionActive());
		$this->assertEquals('', $this->backend->getCurrentUserId());
	}

    public const ENCRPYT2_SIGN_TOKEN = "eyJwMnMiOiJWSTRQS0ZCeVRyUSIsInAyYyI6MTAwMCwiY3R5IjoiSldUIiwiZW5jIjoiQTI1NkNCQy1IUzUxMiIsImFsZyI6IlBCRVMyLUhTNTEyK0EyNTZLVyJ9.YQlaJwr-og6DNQhCkszfsts2z2NLuWsP5czCbMQdyhqjBuhutAvdZlqkFD6el4OeupoXXkTb7XkNyNZVq5S-rfUNGptv27J9.mNCv0KWUDXJoVLxkyppGqg.BdjbqWD14kmuJfLhVMWInuDjTh5O_qxjF9n9rD3viGH1WXZvQtiPT9U2ZKN17jLyzhLXtmvPP_bGZZPrGc5p68WoAteCSxzwJRGcF0hzO6gBhgvx_CcddG0jWcfaXgsFbOeLBpZMKR3w8_6I6shxDcrm0vwL_xeSOd_m4me_VVPQGkaOPKrMy4Ywlh-H7DTquI4NgC1vqt-B7Mpowj82PifFSgEDVrFPkNsustl4PE_2IiL5s_YAPme-OKq50wXzjcjsKAWEbgfsTk5iPoEJNaNWPyWUKiQ8Zp3w6qQgsiY7EGKB5D_-cgbkpq7GmASTiV0FbWHlKleQmHlZ0yJe-WMn0Ai_feVrNwsDM1X5QJ0YMyk5otef-s_64vnLCyo4VbLexO3d67dUqut03xdb9c2SLrupLzpONAJ-nNJ2vNbfr2EBZiSHYjttsmRXlAXgRhiJZIdUGDxBJO-ydEaR22VtPK8pdX9s2Sv8t609xeNQA9hjxCT6IRtEv7vJ0sODV-LSJetO3RKYdBOzNUUvz5VHDE6ogLWNF5blvQ8JoImJd8XP4rNmasassb1NHOPFr4lO7r4ZIn4vmb_idBjzWO2940o48vO5MoRT9gN9rUZDhTwK2enuKdek10PmsVIII5Q18DwvDZhRfM1ZbqZRdkKpnkVb-nWqXChHcSgFcR-TXZGmh3WaH6OJWKpckBAoQ1OHZDl2h_lIfCJ7-eOHR2i3tpXEp6URi31iABcsUZniv8hxB1XYORu9Bl63BQ_t6ns3L-wlMb-LAcvk_sruyObIAuhiZzCyJGxaugje0znGMd3vSXi4U-oqnuGKlKu_1-o7-qB-f1Pkfl6UCk5mS6Vnq-P78FN0iIGaeT8FwsrX-uAFpO8HH4YYEeE8yTi0CQShXVYPiisAQIQFg6QBjy5zEXUZnMBfG-iQ4lfxBJg2sGZ7-HAZpYB2RXDVXAUi4fqI8A1RdHpQofqFGyQZtfVPviOhfNw9Xx79GXb7Cw7viaHFFeyocbyk-55bqjRKpWPP758oxsmP7LZn7yVbMRciCiGDB0LNA1_vJ-7qi9oIUFGdoEW0r3y9I8Su3TH2H2P7HjVaIojOwY4z5_EuADg3lzoSACPvR_I7_r5zMqm7g89HDOo7b-_wh46JVpORbCemQwvJQehN6MUJTbBv_rLKCJ_wjNNMF9sa29yUvoUmEFvlLLy2e2p_r-4AnfGP5P1givxxh12pS_c64XZ1SLqaALTARRwkv1HCnufTNmit80-5rRghgAANf4KXcppXDoMqKW-mrI1Q_ckrkVb7vJuEHaPB1cka5MLIpQ9dFz2iwAEZcFDXXpx2u_ySSDSzRItgazuSOk7DMJzTER_aMTOP2IwzVPoGK8K3RT7wS0lNGfalepX-BAcAbZz4md2PAgHPcfKt1czhdBO5DO9mhKLSSHNA2cc4MmE4_3Ir3BfQCL7mQExvy5mESVr05eTIvLBAzae6SimwzkAUz3o6sxU0neTfxyM47zwQYutOvyC5MCHcA00HdLcyRG9PaE3Bsu5n1WJpIY8i217eFvBZXTIBM-b9vS2_lfC_nNC9DB4N33B2DFEkH02uk9L8vOY90vunGKX-qLXahFOWV_WrFxi_jKzav1FIGV0FcK8QPU8UC9tF9cbxKE1DyLu_G1I9XHP8KO7y9bKOGNv1sRDSUiGZX1_COPM6cifpJsEhOLsucmGsybKg2C77cXhuou9OSen89Devr2ZzWtSZOg1HQdAJuFVkQhjAKcygW49mKqvXsUytRkWEN1mOPsuIJgmt3t4-bxvxeH9qITjy7gR8KYCY5sgdeaIhiEmc2hVp1cBo_HMQNo1E1ew0l8K5X1gavEbUd3RCcRBEtsekwTsfGFoQ6rivH_F5PwAlhMde9jN-I3fnPZMlPnTQEBpb3RdcPV8YNJ7RzRVbQJktdDqb_be1L3BYzKuK8hnv4aEu4Y0wYLRkBxYNIW70X06bIeyCC7B07xn5yLrUaC0MS4UxO9gSPEdauj1OBP7Z_va7zNIbOr4CI68QLfUwtoWpYLPag1exLADeQO3Cdd1qX9LU2trhNVNsw_NapqVkguAI3A3YTuaCQpt68kKGhsugiJ7DsxHuWoNzou4hejBQAvJ1Lm-N38DFKB47gDrwraafpRAezpCyclpaQeYbMK_rz12YCbl35PkFqDefL7B4EESJyk_Wzqpl6Y3AU81rrXK2aVaO0iuVuunWc492tullX_TQ4rtcX_URyZBKz9eF6dxwMJM5UNTtnz7uq-oOmxL3o80XSLpSbfHM4p9elkZGsXfsgpPj0DQJ7EAneLGRqncdLC-6d_ry2E5HwtcC8iWS51CFttDoVyatDDdEWOB7WxD0wy63uc8XK58PPc_ped8W53bid3jB2E5Bg0_c63KQ83U7fezzMtFhUzLIc83FzsG9D4hAPGvZowj3IOAh-E1FlvjvHThse_iH2lIoA1sC9WHpUFx3RkalAaN76fAWP-3xO-bckk9AR3XX1pPxYnx0kOq0a4GR9G7y_ylBt6zGZ0E8TUg8VHS5i834V_rh15R3o8pHncq8b7kwAA--EWCuiLP8B7gTgMqS58r9G89PfZa7u9Wf4NkjoBvZbKzfbnZmPzXkuSLPyC4VBcAp9hZSzdTTd67zLYikGij5dSZ3TRFFG6MSvGDBYvs2P9KaixhcJbY6a7ULGbeBpB29rnq4OEXoGMjOoyG171ZzIeuXAvZnk_ujhEWlCFvznvfQu8H5mTjtFb17I9BJ4YS5gT3E5UwHEH_bAaJI8KtRjfbhKkv09cxaYqRjCMoPlLEPnwDxc2Ousux5SHOjgIqWp9z1acIUzLqkbK3euZNL1YpCNRJTMn4qDPhel5gyY9IjoqgEhfQFJ4ckp2_DLGcFZj3Wwwh-WGmkduvTr2TE_kIA-SmXcqwyGdLse3n7JUHVxcumvXgr5oxe2I_h6UQGSPLxz-KwKxeIUAARQhM9f2mjBcnJ3hkaJj-ciuAjof-WBVCZJsjlccogXhXtxLbjz8ZSntQuaLdjb-ci2wMANhPWnWh9R2KqnREhp-PTllAG4Bj-BWmpzTTRy7tZGkFKoL1xiZMCFA_5egS9V1lqwz62BVOVZ7AeZ5NK8hjGnzSgq6E3bhLoTDupPJLUl3f7fC16PqHQjb049Srme9lK13s8oR79g9UUufW-jQloUhA5fRql45ArveLSTSgg-nUCk22Dso1-Cjk7BIqsEFmeBcyhQoqpjCiuKT6iiVTuEnQXAJ8WEi_hJKTXJ2NxEOdaCG1VaZNycggvX4urmkD53HLpXABitdYpBqJvu-DkO-K8OZA0v8tThBZx4zrIY5EMUPi9YikMrWOqeJtXhA6ZYpeUjK8FHM-sAb3i377lw0CarC8XDzzeNCHRJvaksZdhviuBqNjWXQ_VtU6xEqXsXc8FSftvK2SoSiW19qgiQkrUMJxSy6A_daXT0b7FucBACN1O3YDQ2-x6juM1uMjLico4I1OeFP0RsbUazYVdW0wL6CXiC81ygyTk_XE85xyWwNyiooBuJc377qapNcbUVAYca6R5YVHLVsVLjr3h_BlO1KWv064dypH1faO8cYatSwXp5ttcUg8xoI6E_q0N3IUepfTleZBiCRncoFyKcOT7xUlqojhkC4YirwgtV5Pv3hp6MQ9hjibUeX8mNLFepE1tDFyzZmMXM2kr0Q99WVINbRqv8vGjt82wuZScuJiBy8P6BV-FJLAXsECrAtauSQlDP7YTWsibeqQ3_LEDRd4G9BMj7RorJg6Z0jFloIVfzQOHkZCEZITbh8ifrDrnpMO84l-__kRVImb1rW6I-1KdTubMAaZbAYPhpiYWmC5FJfmyyCSA7uuqeP7RWSm3fZeJK-YinLKH6dUHgwchPQ1godY97ywznP5YuM9pmve75iaNcd3ILuljGx8eBj2Ig7lkPK00JId6FfDwfg9h9cgAKfqueZRBPEN0D3grwZkplG7-_6B1ZhmwjRHaFY88L4EUVnqNh9F73190G-oOuM8Ztw0ItfLU-EvshvMLZ_4W-FUN8B_okqAGH0F088j5ZADxS7HdWMq0DNDIaXpDgPjPhLT7mng20O7BWfG8nTSMEqTBGfvpgoeTL5LjBuDESG4H7FhxGXlfum8asCs8WgdhZ0Zh-SRV8bcLTcpOSEuutdCOK0DxMjs30MTijfLDfpHQP9_fWuG__3n-9g-7Rs6OIaU9jwJ2yWarC-CfPX7yzZcgcsAbT_UEHqRZXQU5vhepV5tmvM5RTv9k7a16b6xIEJIBNLaDRw7LZaauowiaF40vrMNZNGnqqTED_bqMcnfYXvp2R0QFZihNgey1rh2ndhYcSmXSC0F4Wm6r4T6q9VfW_T4Y7NGb31a001Mq_edR2xa_uSBETzybCsHNUq5bD_F3Qj4JUivq2nyh-UAbxP71MdlGE8RN5RYL7b5j25o1oyw5tSYbndIjfp_oVHkdWtnYJsH6T131lUwM0-DwMWWtLParbukDjDjy08aTEDR0vW6LaJJ9bh1_Po-XR6sG4lAeTcJo7XjptIWQCbkSrV6gD7GXOOJgF2qVlvM02ARNLl6DNo3Y7ar_H4LkZ3aAkkV1Yy7-vnVpIEx-UoSnilNRQN_rp6icTwNilt1UnuuLutxKISHRMDP3Pv9vEATDQy-z.w6KkNgIIeh8SPlMtA6l7dbywsDAKFLkTmrVc65q-BL8";

    public function testEncryptedRealSignature2() {
		$this->requestMock->expects($this->any())
						->method('getHeader')
						->with($this->equalTo(Application::OIDC_API_REQ_HEADER))
						->willReturn("Bearer " . self::ENCRPYT2_SIGN_TOKEN);

		$this->assertTrue($this->backend->isSessionActive());
		$this->assertEquals('', $this->backend->getCurrentUserId());
	}


}
