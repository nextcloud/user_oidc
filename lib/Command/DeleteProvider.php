<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\UserOIDC\Db\ProviderMapper;

use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteProvider extends Base {

	public function __construct(
		private ProviderMapper $providerMapper,
		private ProviderService $providerService,
	) {
		parent::__construct();
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
