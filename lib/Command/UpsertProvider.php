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

use Exception;
use \Symfony\Component\Console\Command\Command;

use OCA\UserOIDC\Db\ProviderMapper;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpsertProvider extends Command {
	private $providerMapper;

	public function __construct(ProviderMapper $providerMapper) {
		parent::__construct();
		$this->providerMapper = $providerMapper;
	}

	protected function configure() {
		$this
			->setName('user_oidc:provider')
			->setDescription('Create, show or update a OpenId connect provider config given the identifier of a provider')
			->addArgument('providerid', InputOption::VALUE_REQUIRED, 'Administrative identifier name of the provider in the setup')
			->addOption('clientid', 'c', InputOption::VALUE_REQUIRED, 'OpenID client identifier')
			->addOption('clientsecret', 's', InputOption::VALUE_REQUIRED, 'OpenID client secret')
			->addOption('discoveryuri', 'd', InputOption::VALUE_REQUIRED, 'OpenID discovery endpoint uri')
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

		$providerid = $input->getArgument('providerid');
		$clientid = $input->getOption('clientid');
		$clientsecret = $input->getOption('clientsecret');
		$discoveryuri = $input->getOption('discoveryuri');

		if ($providerid === null) {
			return $this->listProviders($input, $output);
		}

		// show (unprotected) data in case no field is given
		try {
			if ((null === $clientid) &&
				 (null === $clientsecret) &&
				 (null === $discoveryuri)) {
				$provider = $this->providerMapper->findProviderByIdentifier($providerid);
				if ($outputFormat === 'json') {
					$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR));
					return 0;
				}

				if ($outputFormat === 'json_pretty') {
					$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
					return 0;
				}

				$table = new Table($output);
				$table->setHeaders(['ID', 'Identifier', 'Discovery endpoint', 'Client ID']);
				$table->addRow( [
					$provider->getId(),
					$provider->getIdentifier(),
					$provider->getDiscoveryEndpoint(),
					$provider->getClientId()
				]);
				$table->render();
				return 0;
			}

			$this->providerMapper->createOrUpdateProvider($providerid, $clientid, $clientsecret, $discoveryuri);
		} catch (Exception $e) {
			$output->writeln($e->getMessage());
			return -1;
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
