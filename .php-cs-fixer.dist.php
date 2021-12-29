<?php

declare(strict_types=1);

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('node_modules')
	->notPath('l10n')
	->notPath('src')
	->notPath('vendor')
	->notPath('lib/Vendor')
	->in(__DIR__);
return $config;
