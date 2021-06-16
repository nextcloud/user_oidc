<?php declare(strict_types=1);
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

use OC\Core\Command\Base;

use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\Provider;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteProvider extends Base {
	private $providerMapper;

	public function __construct(ProviderMapper $providerMapper) {
		parent::__construct();
		$this->providerMapper = $providerMapper;
	}

	protected function configure() {
		$this
			->setName('user_oidc:provider:delete')
			->setDescription('Delete OpenId connect provider connfig')
			->addArgument('providerid', InputArgument::REQUIRED, 'Identification name of the provider config entry')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		try {
			$providerid = $input->getArgument('providerid');
			$helper = $this->getHelper('question');
			$question = new ConfirmationQuestion('Are you sure you want to delete OpenID Provider ' . $providerid . '\nand may invalidate all assiciated user accounts.', false);
			if ($input->getOption('force') || $helper->ask($input, $output, $question)) {
				$this->providerMapper->deletePovider($providerid);
			}
		} catch(Exception $e) {
			$output->writeln($e->getMessage());
			return -1;
		}
	}
}
