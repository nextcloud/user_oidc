<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// Load composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Register OCP namespace from nextcloud/ocp package (not auto-registered by composer)
$ocpPath = __DIR__ . '/../vendor/nextcloud/ocp';
$libPath = __DIR__ . '/../lib';

spl_autoload_register(function ($class) use ($ocpPath, $libPath) {
	// Handle OCA\UserOIDC namespace (the app classes)
	if (strpos($class, 'OCA\\UserOIDC\\') === 0) {
		$relativePath = str_replace('\\', '/', substr($class, 13)); // Remove 'OCA\UserOIDC\'
		$file = $libPath . '/' . $relativePath . '.php';
		if (file_exists($file)) {
			require_once $file;
			return true;
		}
	}
	// Handle OCP namespace
	if (strpos($class, 'OCP\\') === 0) {
		$relativePath = str_replace('\\', '/', substr($class, 4));
		$file = $ocpPath . '/OCP/' . $relativePath . '.php';
		if (file_exists($file)) {
			require_once $file;
			return true;
		}
	}
	// Handle OC namespace
	if (strpos($class, 'OC\\') === 0) {
		$relativePath = str_replace('\\', '/', substr($class, 3));
		$file = $ocpPath . '/OC/' . $relativePath . '.php';
		if (file_exists($file)) {
			require_once $file;
			return true;
		}
	}
	return false;
});

// Define OC class stub if not defined by stubs
if (!class_exists('OC')) {
	class OC {
		public static $server;
	}
}

// Load stubs for OC-namespaced classes (these extend OCP classes, so must be loaded after autoloader)
// Load stubs in dependency order - base stubs first
$stubOrder = [
	'oc_hooks_emitter.php',
	'oc_user.php',
	'oc_app.php',
	'oc_authentication_token_provider.php',
	'oc_core_command_base.php',
	'oc_user_session.php',
	'oc_util.php',
	'oca_files_events.php',
	'oca_oidc_events.php',
	'ocp_imapperexception.php',
	'ocp_token_invalidated_event.php',
];

foreach ($stubOrder as $stubFile) {
	$stubPath = __DIR__ . '/stubs/' . $stubFile;
	if (file_exists($stubPath)) {
		require_once $stubPath;
	}
}
