<?php declare(strict_types=1);
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

use OC\Core\Command\Base;

use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\Provider;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpsertProvider extends Base {
	private $providerMapper;

	public function __construct(ProviderMapper $providerMapper) {
		parent::__construct();
		$this->providerMapper = $providerMapper;
	}

	protected function configure() {
		$this
			->setName('user_oidc:provider')
			->setDescription('Create, show or update a OpenId connect provider connfig given the identifier of a provider')
			->addArgument('providerid', InputOption::VALUE_REQUIRED, 'Administrative identifier name of the provider in the setup')
			->addOption('clientid', 'c', InputOption::VALUE_REQUIRED, 'OpenID client identifier')
			->addOption('clientsecret', 's', InputOption::VALUE_REQUIRED, 'OpenID client secret')
			->addOption('discoveryuri', 'e', InputOption::VALUE_REQUIRED, 'OpenID discovery endpoint uri');

		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$providerid   = $input->getArgument('providerid');
		$clientid     = $input->getOption('clientid');
		$clientsecret = $input->getOption('clientsecret');
		$discoveryuri = $input->getOption('discoveryuri');

		if (false === $clientid) {
			// in this case, the option was not passed when running the command
			// handle it the same as a lacking value
			$clientid = null;
		}
		if (false === $clientsecret) {
			// in this case, the option was not passed when running the command
			// handle it the same as a lacking value
			$clientsecret = null;
		}
		if (false === $discoveryuri) {
			// in this case, the option was not passed when running the command
			// handle it the same as a lacking value
			$discoveryuri = null;
		}

		// show (unprotected) data in case no field is given
		try {
			if ( (null === $clientid) && 
			     (null === $clientsecret) && 
				 (null === $dicoveryuri) ) {
				$provider = $this->providerMapper->findProviderByIdentifier($providerid);
				if (null === $provider) {
					return -4;
				}
				$output->write("{ 'identifier': '" . $provider->getIdentifier() . "', ");
				$output->write("'clientid': '" . $provider->getClientId() . "', ");   
				$output->write("'clientsecret': '***', ");   
				$output->write("'discoveryuri': '" . $provider->getDiscoveryEndpoint() . "', ");   
				$output->writeln("}");
			} else {
				$this->providerMapper->createOrUpdateProvider($providerid, $clientid, $clientsecret, $discoveryuri);
			}
		} catch(Exception $e) {
			$output->writeln($e->getMessage());
			return -1;
		}	

		return 0;
	}
}
