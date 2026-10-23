<?php

declare(strict_types=1);

/**
 * __DIR__-relative paths do NOT work here: in this docker-dev setup,
 * apps-extra/office is bind-mounted separately from the rest of the server
 * tree, so PHP's realpath() resolution for __DIR__ inside this directory
 * reports the host-side bind-mount source, not /var/www/html/... - three
 * levels up from there lands outside the container filesystem entirely and
 * the require fails. The NC server root is reliably /var/www/html inside
 * this container regardless of where this app happens to be mounted from;
 * override via NEXTCLOUD_ROOT for other setups.
 */
$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';

require_once $nextcloudRoot . '/tests/bootstrap.php';

/**
 * Deliberately NOT requiring this app's own vendor/autoload.php here.
 *
 * Verified by reproduction: with the real server bootstrap loaded first,
 * additionally requiring vendor/autoload.php still causes OCP\IUser (and
 * presumably other OCP\* classes) to resolve to the composer-dev-only
 * `vendor/nextcloud/ocp` STUB package instead of the real server's
 * lib/public/*.php - this app's ClassLoader wins the autoload race for
 * classes the real server's classmap-based loader hasn't already loaded,
 * shadowing the real interfaces with the (possibly differently-versioned)
 * stub ones. That surfaced as a hard fatal:
 *   Declaration of OC\User\User::setEnabled(bool $enabled = true) must be
 *   compatible with OCP\IUser::setEnabled(bool $enabled = true): void
 * The stub OCP\ mapping in this app's composer.json autoload-dev exists
 * ONLY for tests/bootstrap-unit.php, where no real OCP exists to shadow.
 *
 * OCA\Office\ itself doesn't need autoloading here either -
 * \OC_App::loadApp() below registers it via the real server's own
 * app-loading mechanism. The only thing left unresolved is the shared
 * IntegrationTestCase base class: PHPUnit's own <directory suffix="Test.php">
 * discovery requires each *Test.php file itself (that's what actually
 * defines those classes), but it never touches IntegrationTestCase.php since
 * that filename doesn't match the suffix pattern - require it explicitly so
 * `extends IntegrationTestCase` resolves. (Tried eagerly require_once-ing
 * every file in tests/integration/ here instead; that silently broke
 * PHPUnit's own discovery for 2 of the 6 classes - it appears to use a
 * before/after get_declared_classes() diff per file to identify which class
 * a file defines, and finds nothing new for a file this bootstrap already
 * loaded. A single explicit require for just the non-Test.php file avoids
 * that entirely. Also: no PSR-4 autoload rule is used for this namespace -
 * the "Integration" segment (PascalCase, by convention) doesn't match the
 * "integration" directory (lowercase, per the plan) and would silently fail
 * to resolve on this container's case-sensitive filesystem.)
 */
require_once __DIR__ . '/integration/IntegrationTestCase.php';

\OC_App::loadApp(OCA\Office\AppInfo\Application::APP_ID);
OC_Hook::clear();
