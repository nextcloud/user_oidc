<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	// Allow running on PHP versions newer than what php-cs-fixer officially supports
	->setUnsupportedPhpVersionAllowed(true)
	->getFinder()
	->notPath('build')
	->notPath('node_modules')
	->notPath('l10n')
	->notPath('src')
	->notPath('vendor')
	->notPath('lib/Vendor')
	->in(__DIR__);
return $config;
