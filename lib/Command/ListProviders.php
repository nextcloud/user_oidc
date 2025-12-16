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
use OCP\Security\ICrypto;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListProviders extends Base {

	public function __construct(
		private ProviderMapper $providerMapper,
		private ProviderService $providerService,
		private ICrypto $crypto,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('user_oidc:providers')
			->setDescription('List all providers and print their configuration')
			->addOption('sensitive', 's', InputOption::VALUE_NONE, 'Obfuscate sensitive values like the client ID and the discovery endpoint domain name');
		$this->defaultOutputFormat = self::OUTPUT_FORMAT_JSON_PRETTY;
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$outputFormat = $input->getOption('output') ?? 'json_pretty';
		$sensitive = $input->getOption('sensitive');

		$providers = $this->providerMapper->getProviders();

		$providersWithSettings = array_map(function ($provider) use ($sensitive) {
			$providerSettings = $this->providerService->getSettings($provider->getId());
			$serializedProvider = $provider->jsonSerialize();
			if ($sensitive) {
				$serializedProvider['clientId'] = '********';
				$serializedProvider['clientSecret'] = '********';
				try {
					$discoveryDomainName = parse_url($serializedProvider['discoveryEndpoint'], PHP_URL_HOST);
					$serializedProvider['discoveryEndpoint'] = str_replace($discoveryDomainName, '********', $serializedProvider['discoveryEndpoint']);
				} catch (\Exception|\Throwable) {
				}
			} else {
				$serializedProvider['clientSecret'] = $this->crypto->decrypt($provider->getClientSecret());
			}
			return array_merge($serializedProvider, ['settings' => $providerSettings]);
		}, $providers);

		if ($outputFormat === 'json') {
			foreach ($providersWithSettings as $provider) {
				$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR));
			}
			return 0;
		}

		if ($outputFormat === 'json_pretty') {
			foreach ($providersWithSettings as $provider) {
				$output->writeln(json_encode($provider, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
			}
			return 0;
		}

		$output->writeln(
			'<comment>Only "' . self::OUTPUT_FORMAT_JSON . '" and "' . self::OUTPUT_FORMAT_JSON_PRETTY . '" output formats are supported.</comment>',
		);

		$output->writeln(
			'<comment>Use "--output=' . self::OUTPUT_FORMAT_JSON . '" or "--output=' . self::OUTPUT_FORMAT_JSON_PRETTY . '" '
				. '(default format is "' . self::OUTPUT_FORMAT_JSON_PRETTY . '")</comment>',
		);
		return 0;
	}
}
