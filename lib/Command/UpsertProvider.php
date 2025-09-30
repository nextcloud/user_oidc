<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Command;

use OC\Core\Command\Base;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Security\ICrypto;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpsertProvider extends Base {

	private const EXTRA_OPTIONS = [
		'unique-uid' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_UNIQUE_UID,
			'description' => 'Determines if unique user ids shall be used or not. 1 to enable, 0 to disable',
		],
		'check-bearer' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_CHECK_BEARER,
			'description' => 'Determines if Nextcloud API/WebDav calls should check the Bearer token against this provider or not. 1 to enable, 0 to disable (default when creating a new provider)',
		],
		'bearer-provisioning' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_BEARER_PROVISIONING,
			'description' => 'Determines if Nextcloud API/WebDav calls should automatically provision the user, when sending API and WebDav Requests with a Bearer token. 1 to enable, 0 to disable (default when creating a new provider)',
		],
		'send-id-token-hint' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_SEND_ID_TOKEN_HINT,
			'description' => 'Determines if ID token should be included as a parameter to the end_session_endpoint URL when using unified logout. 1 to enable, 0 to disable',
		],
		'mapping-display-name' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_DISPLAYNAME,
			'description' => 'Attribute mapping of the display name',
		],
		'mapping-email' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_EMAIL,
			'description' => 'Attribute mapping of the email address',
		],
		'mapping-quota' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_QUOTA,
			'description' => 'Attribute mapping of the quota',
		],
		'mapping-uid' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_UID,
			'description' => 'Attribute mapping of the user id',
		],
		'extra-claims' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_EXTRA_CLAIMS,
			'description' => 'Extra claims to request when getting tokens',
		],
		'mapping-language' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_LANGUAGE,
			'description' => 'Attribute mapping of the account language',
		],
		'mapping-locale' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_LOCALE,
			'description' => 'Attribute mapping of the account locale',
		],
		'mapping-website' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_WEBSITE,
			'description' => 'Attribute mapping of the website',
		],
		'mapping-avatar' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_AVATAR,
			'description' => 'Attribute mapping of the avatar',
		],
		'mapping-twitter' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_TWITTER,
			'description' => 'Attribute mapping of twitter',
		],
		'mapping-fediverse' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_FEDIVERSE,
			'description' => 'Attribute mapping of the fediverse',
		],
		'mapping-organisation' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_ORGANISATION,
			'description' => 'Attribute mapping of the organisation',
		],
		'mapping-role' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_ROLE,
			'description' => 'Attribute mapping of the role',
		],
		'mapping-headline' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_HEADLINE,
			'description' => 'Attribute mapping of the headline',
		],
		'mapping-biography' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_BIOGRAPHY,
			'description' => 'Attribute mapping of the biography',
		],
		'mapping-phone' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_PHONE,
			'description' => 'Attribute mapping of the phone',
		],
		'mapping-gender' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_GENDER,
			'description' => 'Attribute mapping of the gender',
		],
		'mapping-pronouns' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_PRONOUNS,
			'description' => 'Attribute mapping of the pronouns',
		],
		'mapping-birthdate' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_BIRTHDATE,
			'description' => 'Attribute mapping of the birth date',
		],
		'mapping-address' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_ADDRESS,
			'description' => 'Attribute mapping of the address',
		],
		'mapping-street_address' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_STREETADDRESS,
			'description' => 'Attribute mapping of the street address',
		],
		'mapping-postal_code' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_POSTALCODE,
			'description' => 'Attribute mapping of the postal code',
		],
		'mapping-locality' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_LOCALITY,
			'description' => 'Attribute mapping of the locality',
		],
		'mapping-region' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_REGION,
			'description' => 'Attribute mapping of the region',
		],
		'mapping-country' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_COUNTRY,
			'description' => 'Attribute mapping of the country',
		],
		'group-provisioning' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_GROUP_PROVISIONING,
			'description' => 'Flag to toggle group provisioning. 1 to enable, 0 to disable (default)',
		],
		'group-whitelist-regex' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_GROUP_WHITELIST_REGEX,
			'description' => 'Group whitelist regex',
		],
		'group-restrict-login-to-whitelist' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_RESTRICT_LOGIN_TO_GROUPS,
			'description' => 'Restrict login for users that are not in any whitelisted groups. 1 to enable, 0 to disable (default)',
		],
		'mapping-groups' => [
			'shortcut' => null, 'mode' => InputOption::VALUE_REQUIRED, 'setting_key' => ProviderService::SETTING_MAPPING_GROUPS,
			'description' => 'Attribute mapping of the groups',
		],
		'resolve-nested-claims' => [
			'shortcut' => null,
			'mode' => InputOption::VALUE_REQUIRED,
			'setting_key' => ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING,
			'description' => 'Enable support for dot-separated and fallback claim mappings (e.g. "a.b | c.d | e"). 1 to enable, 0 to disable (default)',
		],
	];

	public function __construct(
		private ProviderService $providerService,
		private ProviderMapper $providerMapper,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('user_oidc:provider')
			->setDescription('Create, show or update a OpenId connect provider config given the identifier of a provider')
			->addArgument('identifier', InputArgument::OPTIONAL, 'Administrative identifier name of the provider in the setup')
			->addOption('clientid', 'c', InputOption::VALUE_REQUIRED, 'OpenID client identifier')
			->addOption('clientsecret', 's', InputOption::VALUE_REQUIRED, 'OpenID client secret')
			->addOption('discoveryuri', 'd', InputOption::VALUE_REQUIRED, 'OpenID discovery endpoint uri')
			->addOption('endsessionendpointuri', 'e', InputOption::VALUE_REQUIRED, 'OpenID end session endpoint uri')
			->addOption('postlogouturi', 'p', InputOption::VALUE_REQUIRED, 'Post logout URI')
			->addOption('scope', 'o', InputOption::VALUE_OPTIONAL, 'OpenID requested value scopes, if not set defaults to "openid email profile"');
		foreach (self::EXTRA_OPTIONS as $name => $option) {
			$this->addOption($name, $option['shortcut'], $option['mode'], $option['description']);
		}
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$outputFormat = $input->getOption('output') ?? 'table';

		$identifier = $input->getArgument('identifier');
		$clientid = $input->getOption('clientid');
		$clientsecret = $input->getOption('clientsecret');
		if ($clientsecret !== null) {
			$clientsecret = $this->crypto->encrypt($clientsecret);
		}
		$discoveryuri = $input->getOption('discoveryuri');
		$endsessionendpointuri = $input->getOption('endsessionendpointuri');
		$postLogoutUri = $input->getOption('postlogouturi');
		$scope = $input->getOption('scope');

		if ($identifier === null) {
			return $this->listProviders($input, $output);
		}

		// check if any option for updating is provided
		$updateOptions = array_filter($input->getOptions(), static function ($value, $option) {
			return in_array($option, [
				'identifier', 'clientid', 'clientsecret', 'discoveryuri', 'endsessionendpointuri', 'postlogouturi', 'scope',
				...array_keys(self::EXTRA_OPTIONS),
			]) && $value !== null;
		}, ARRAY_FILTER_USE_BOTH);

		if (count($updateOptions) === 0) {
			try {
				$provider = $this->providerMapper->findProviderByIdentifier($identifier);
			} catch (DoesNotExistException $e) {
				$output->writeln('Provider not found');
				return -1;
			}
			$provider = $this->providerService->getProviderWithSettings($provider->getId());
			if ($outputFormat === 'json') {
				$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR));
				return 0;
			}

			if ($outputFormat === 'json_pretty') {
				$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
				return 0;
			}

			$provider['settings'] = json_encode($provider['settings']);
			$table = new Table($output);
			$table->setHeaders(['ID', 'Identifier', 'Client ID', 'Discovery endpoint', 'End session endpoint', 'Advanced settings']);
			$table->addRow($provider);
			$table->render();
			return 0;
		}

		$provider = $this->providerService->getProviderByIdentifier($identifier);
		if ($provider !== null) {
			// existing provider, keep values that are not set, the scope has to be set anyway
			$scope = $scope ?? $provider->getScope();
		} else {
			// new provider default scope value
			$scope = $scope ?? 'openid email profile';
		}
		try {
			$provider = $this->providerMapper->createOrUpdateProvider(
				$identifier, $clientid, $clientsecret, $discoveryuri, $scope, $endsessionendpointuri, $postLogoutUri
			);
			// invalidate JWKS cache (even if it was just created)
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE, '');
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, '');
		} catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return -1;
		}
		foreach (self::EXTRA_OPTIONS as $name => $option) {
			if (($value = $input->getOption($name)) !== null) {
				if (array_key_exists($option['setting_key'], ProviderService::BOOLEAN_SETTINGS_DEFAULT_VALUES)) {
					$value = (string)$value === '0' ? '0' : '1';
				} else {
					$value = (string)$value;
				}
				$this->providerService->setSetting($provider->getId(), $option['setting_key'], $value);
			}
		}
		return 0;
	}

	private function listProviders(InputInterface $input, OutputInterface $output) {
		$outputFormat = $input->getOption('output') ?? 'table';
		$providers = $this->providerMapper->getProviders();

		if ($outputFormat === 'json') {
			$output->writeln(json_encode($providers, JSON_THROW_ON_ERROR));
			return 0;
		}

		if ($outputFormat === 'json_pretty') {
			$output->writeln(json_encode($providers, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
			return 0;
		}

		if (count($providers) === 0) {
			$output->writeln('No providers configured');
			return 0;
		}

		$table = new Table($output);
		$table->setHeaders(['ID', 'Identifier', 'Discovery endpoint', 'End session endpoint', 'Client ID']);
		$providers = array_map(function ($provider) {
			return [
				$provider->getId(),
				$provider->getIdentifier(),
				$provider->getDiscoveryEndpoint(),
				$provider->getEndSessionEndpoint(),
				$provider->getClientId()
			];
		}, $providers);
		$table->setRows($providers);
		$table->render();
		return 0;
	}
}
