<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Bernd Rederlechner tsdicloud@github.com>
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

namespace OCA\UserOIDC\Command;

use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use \Symfony\Component\Console\Command\Command;
use OCA\UserOIDC\Db\ProviderMapper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpsertProvider extends Command {

	/** @var ProviderService */
	private $providerService;
	/** @var ProviderMapper */
	private $providerMapper;

	public function __construct(ProviderService $providerService, ProviderMapper $providerMapper) {
		parent::__construct();
		$this->providerService = $providerService;
		$this->providerMapper = $providerMapper;
	}

	protected function configure() {
		$this
			->setName('user_oidc:provider')
			->setDescription('Create, show or update a OpenId connect provider config given the identifier of a provider')
			->addArgument('identifier', InputArgument::OPTIONAL, 'Administrative identifier name of the provider in the setup')
			->addOption('clientid', 'c', InputOption::VALUE_REQUIRED, 'OpenID client identifier')
			->addOption('clientsecret', 's', InputOption::VALUE_REQUIRED, 'OpenID client secret')
			->addOption('discoveryuri', 'd', InputOption::VALUE_REQUIRED, 'OpenID discovery endpoint uri')

			->addOption('scope', 'o', InputOption::VALUE_OPTIONAL, 'OpenID requested value scopes, if not set defaults to "openid email profile"')
			->addOption('unique-uid', null, InputOption::VALUE_OPTIONAL, 'Flag if unique user ids shall be used or not. 1 to enable (default), 0 to disable.')
			->addOption('check-bearer', null, InputOption::VALUE_OPTIONAL, 'Flag if Nextcloud API/WebDav calls should check the Bearer token against this provider or not. 1 to enable (default), 0 to disable.')
			->addOption('mapping-display-name', null, InputOption::VALUE_OPTIONAL, 'Attribute mapping of the display name')
			->addOption('mapping-email', null, InputOption::VALUE_OPTIONAL, 'Attribute mapping of the email address')
			->addOption('mapping-quota', null, InputOption::VALUE_OPTIONAL, 'Attribute mapping of the quota')
			->addOption('mapping-uid', null, InputOption::VALUE_OPTIONAL, 'Attribute mapping of the user id')

			->addOption(
				'output',
				null,
				InputOption::VALUE_OPTIONAL,
				'Output format (table, json or json_pretty, default is table)',
				'table'
			);
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$outputFormat = $input->getOption('output') ?? 'table';

		$identifier = $input->getArgument('identifier');
		$clientid = $input->getOption('clientid');
		$clientsecret = $input->getOption('clientsecret');
		$discoveryuri = $input->getOption('discoveryuri');
		$scope = $input->getOption('scope');

		if ($identifier === null) {
			return $this->listProviders($input, $output);
		}

		// check if any option for updating is provided
		$updateOptions = array_filter($input->getOptions(), static function ($value, $option) {
			return in_array($option, [
				'identifier', 'clientid', 'clientsecret', 'discoveryuri',
				'scope', 'unique-uid', 'check-bearer',
				'mapping-uid', 'mapping-display-name', 'mapping-email', 'mapping-quota',
			]) && $value !== null;
		}, ARRAY_FILTER_USE_BOTH);

		if (count($updateOptions) === 0) {
			$provider = $this->providerMapper->findProviderByIdentifier($identifier);
			$provider = $this->providerService->getProviderWithSettings($provider->getId());
			if ($outputFormat === 'json') {
				$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR));
				return 0;
			}

			if ($outputFormat === 'json_pretty') {
				$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
				return 0;
			}

			$provider['settings'][ProviderService::SETTING_UNIQUE_UID] = $provider['settings'][ProviderService::SETTING_UNIQUE_UID] ? '1' : '0';
			$provider['settings'] = json_encode($provider['settings']);
			$table = new Table($output);
			$table->setHeaders(['ID', 'Identifier', 'Client ID', 'Discovery endpoint', 'Advanced settings']);
			$table->addRow($provider);
			$table->render();
			return 0;
		}

		$provider = $this->providerService->getProviderByIdentifier($identifier);
		if ($provider !== null) {
			$clientid = $clientid ?? $provider->getClientId();
			$clientsecret = $clientsecret ?? $provider->getClientSecret();
			$discoveryuri = $discoveryuri ?? $provider->getDiscoveryEndpoint();
		}
		$scope = $scope ?? 'openid email profile';
		try {
			$provider = $this->providerMapper->createOrUpdateProvider($identifier, $clientid, $clientsecret, $discoveryuri, $scope);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return -1;
		}
		if (($checkBearer = $input->getOption('check-bearer')) !== null) {
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_CHECK_BEARER, (string)$checkBearer === '0' ? '0' : '1');
		}
		if (($uniqueUid = $input->getOption('unique-uid')) !== null) {
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_UNIQUE_UID, (string)$uniqueUid === '0' ? '0' : '1');
		}

		if ($mapping = $input->getOption('mapping-display-name')) {
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_MAPPING_DISPLAYNAME, $mapping);
		}
		if ($mapping = $input->getOption('mapping-email')) {
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_MAPPING_EMAIL, $mapping);
		}
		if ($mapping = $input->getOption('mapping-quota')) {
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_MAPPING_QUOTA, $mapping);
		}
		if ($mapping = $input->getOption('mapping-uid')) {
			$this->providerService->setSetting($provider->getId(), ProviderService::SETTING_MAPPING_UID, $mapping);
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
		$table->setHeaders(['ID', 'Identifier', 'Discovery endpoint', 'Client ID']);
		$providers = array_map(function ($provider) {
			return [
				$provider->getId(),
				$provider->getIdentifier(),
				$provider->getDiscoveryEndpoint(),
				$provider->getClientId()
			];
		}, $providers);
		$table->setRows($providers);
		$table->render();
		return 0;
	}
}
