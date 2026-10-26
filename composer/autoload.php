<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nextcloud requires apps/<app>/composer/autoload.php for every enabled app at boot
// (AppManager::registerAutoloading) and, because this file exists, skips registering the
// app's lib/ itself. Releases ship vendor/ (composer install --no-dev), whose autoloader
// covers lib/ and the bundled libraries.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
	require_once __DIR__ . '/../vendor/autoload.php';
} else {
	// A checkout without composer install: keep the app's own classes loadable, so only
	// what needs a bundled library (WOPI proof verification) fails, and says so.
	spl_autoload_register(static function (string $class): void {
		$prefix = 'OCA\\Office\\';
		if (!str_starts_with($class, $prefix)) {
			return;
		}
		$file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	});
}
