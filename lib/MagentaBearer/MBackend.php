<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\MagentaBearer;

use OCA\UserOIDC\MagentaBearer\TokenService;
use OCA\UserOIDC\MagentaBearer\SignatureException;
use OCA\UserOIDC\User\AbstractOIDCBackend;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Event\TokenValidatedEvent;
use OCA\UserOIDC\Controller\LoginController;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\LdapService;
use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Service\ProvisioningEventService;
use OCA\UserOIDC\User\Validator\SelfEncodedValidator;
use OCA\UserOIDC\User\Validator\UserInfoValidator;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\IApacheBackend;
use OCP\DB\Exception;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICustomLogout;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IPasswordConfirmationBackend;
use Psr\Log\LoggerInterface;

class MBackend extends AbstractOIDCBackend {

    /**
     * @var TokenService
     */
    protected $mtokenService;

    /**
     * @var ProvisioningEventService
     */
    protected $provisioningService;

	public function __construct(IConfig $config,
								UserMapper $userMapper,
								LoggerInterface $logger,
								IRequest $request,
								ISession $session,
								IURLGenerator $urlGenerator,
								IEventDispatcher $eventDispatcher,
								DiscoveryService $discoveryService,
								ProviderMapper $providerMapper,
								ProviderService $providerService,
                                IUserManager $userManager,
                                TokenService $mtokenService,
                                ProvisioningEventService $provisioningService
                                ) {
		parent::__construct($config, $userMapper, $logger, $request, $session, 
                            $urlGenerator, $eventDispatcher, $discoveryService,
                            $providerMapper, $providerService, $userManager);
	
        $this->mtokenService = $mtokenService;
        $this->provisioningService = $provisioningService;
    }

	public function getBackendName(): string {
		return Application::APP_ID . "\\MagentaBearer";
	}

	/**
	 * Backend is activated if header bearer token is detected.
	 *
	 * @return bool ture if bearer header found
	 */
	public function isSessionActive(): bool {
		// if this returns true, getCurrentUserId is called
		// not sure if we should rather to the validation in here as otherwise it might fail for other backends or bave other side effects
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		// session is active if we have a bearer token (API request) OR if we logged in via user_oidc (we have a provider ID in the session)
		return (preg_match('/^\s*bearer\s+/i', $headerToken) != false);
	}

	/**
	 * Return the id of the current user
	 * @return string
	 */
	public function getCurrentUserId(): string {
		// get the bearer token from headers
		$headerToken = $this->request->getHeader(Application::OIDC_API_REQ_HEADER);
		$headerToken = preg_replace('/^bearer\s+/i', '', $headerToken);
		if ($headerToken === '') {
			$this->logger->debug('No Bearer token');
			return '';
		}

		$providers = $this->providerMapper->getProviders();
		if (count($providers) === 0) {
			$this->logger->debug('no OIDC providers');
			return '';
		}

        // we implement only Telekom behavior (which includes auto-provisioning)
        // so we neglect switches from the upstream Nexrcloud oidc handling

		// try to validate with all providers
		foreach ($providers as $provider) {
			if ($this->providerService->getSetting($provider->getId(), ProviderService::SETTING_CHECK_BEARER, '0') === '1') {
                try {
                    $sharedSecret = $provider->getBearerSecret();
                    $bearerToken = $this->mtokenService->decryptToken($headerToken, $sharedSecret);
                    $this->mtokenService->verifySignature($bearerToken, $sharedSecret);
                    $payload = $this->mtokenService->decode($bearerToken);
                    $this->mtokenService->verifyClaims($payload, ['http://auth.magentacloud.de']);
                }
                catch (InvalidTokenException $eToken) {
                    // there is
                    $this->logger->debug('Invalid token:' . $eToken->getMessage(). ". Trying another provider.");
                    continue;
                }
                catch (SignatureException $eSignature) {
                    // only the key seems not to fit, so try the next provider
                    $this->logger->debug($eSignature->getMessage() . ". Trying another provider.");
                    continue;
                } 
                catch (\Throwable $e) {
                    // there is
                    $this->logger->debug('General non matching provider problem:' . $e->getMessage());
                    continue;
                }

                $uidAttribute = $this->providerService->getSetting($provider->getId(), ProviderService::SETTING_MAPPING_UID, 'sub');
                $userId = $payload->{$uidAttribute};
                if ($userId === null) {
                    $this->logger->debug('No extractable user id, check mapping!');
                    return '';        
                }
    
                // check bearercache here, not skipping validation for security reasons
    
                // Telekom bearer does not support refersh_token, so the pupose of TokenValidatedEvent is not given,
                // but could produce trouble if not send with the field, apart from performance aspects.
                // 
                // $discovery = $this->discoveryService->obtainDiscovery($provider);
                // $this->eventDispatcher->dispatchTyped(new TokenValidatedEvent(['token' => $payload], $provider, $discovery));
    
                try {
                    $this->provisioningService->provisionUser($userId, $provider->getId(), $payload);
                    $this->checkFirstLogin($userId); // create the folders same as on web login
                    return $userId;
                } catch (ProvisioningDeniedException $denied) {
                    $this->logger->error('Bearer token access denied: ' . $denied->getMessage());
                    return '';
                }
            }
        }    

		$this->logger->debug('Could not find provider for token');
		return '';
	}

    /**
     * FIXXME: send proper error status from BAckend errors
     * 
	 * This function sets an https status code here (early in the failing backend operation)
	 * to pass on bearer errors cleanly with correct status code and a readable reason
	 * 
	 * For this,  there is a "tricky" setting of a header needed to make it working in all
	 * known situations, see
	 * https://stackoverflow.com/questions/3258634/php-how-to-send-http-response-code
	 */
	// protected function sendHttpStatus(int $httpStatusCode, string $httpStatusMsg) {
	// 	$phpSapiName    = substr(php_sapi_name(), 0, 3);
	// 	if ($phpSapiName == 'cgi' || $phpSapiName == 'fpm') {
	// 		header('Status: ' . $httpStatusCode . ' ' . $httpStatusMsg);
	// 	} else {
	// 		$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
	// 		header($protocol . ' ' . $httpStatusCode . ' ' . $httpStatusMsg);
	// 	}
	// }
}
