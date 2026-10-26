<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Mozart only prefixes the namespaces of the packages a dependency requires.
 * web-token/jwt-library only suggests spomky-labs/aes-key-wrap, so its imports
 * of the AESKW namespace are left untouched and need to be prefixed here.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit(1);
}

$vendorDir = __DIR__ . '/../lib/Vendor/Jose';
if (!is_dir($vendorDir)) {
	return;
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendorDir, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$path = $file->getPathname();
	$content = file_get_contents($path);
	$fixedContent = preg_replace('/^use AESKW\\\\/m', 'use OCA\\\\UserOIDC\\\\Vendor\\\\AESKW\\\\', $content);
	if ($fixedContent !== $content) {
		file_put_contents($path, $fixedContent);
	}
}
