<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Robin Appelman <robin@icewind.nl>
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
use OCP\AppFramework\Db\DoesNotExistException;
use \Symfony\Component\Console\Command\Command;

use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteProvider extends Command {
	private $providerMapper;
	/**
	 * @var ProviderService
	 */
	private $providerService;

	public function __construct(ProviderMapper $providerMapper, ProviderService $providerService) {
		parent::__construct();
		$this->providerMapper = $providerMapper;
		$this->providerService = $providerService;
	}

	protected function configure() {
		$this
			->setName('user_oidc:provider:delete')
			->setDescription('Delete an OpenId connect provider')
			->addArgument('identifier', InputArgument::REQUIRED, 'Administrative identifier name of the provider to delete')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		try {
			$identifier = $input->getArgument('identifier');
			try {
				$provider = $this->providerMapper->findProviderByIdentifier($identifier);
			} catch (DoesNotExistException $e) {
				$output->writeln('Provider not found.');
				return -1;
			}
			$helper = $this->getHelper('question');
			$question = new ConfirmationQuestion('Are you sure you want to delete OpenID Provider "' . $provider->getIdentifier() . '"? It may invalidate all associated user accounts [y/N] ', false);
			if ($input->getOption('force') || $helper->ask($input, $output, $question)) {
				$this->providerMapper->delete($provider);
				$this->providerService->deleteSettings($provider->getId());
				$output->writeln('"' . $provider->getIdentifier() . '" has been deleted.');
			}
		} catch (Exception $e) {
			$output->writeln($e->getMessage());
			return -1;
		}
		return 0;
	}
}
