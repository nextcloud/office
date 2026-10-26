<?php

declare(strict_types=1);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require_once __DIR__ . '/../vendor/autoload.php';

// The OCP and PSR stubs are mapped here, for unit tests only. In composer.json's autoload-dev
// they would also be in a developer's vendor/autoload.php, which Nextcloud loads through
// composer/autoload.php, and shadow the running server's own OCP classes.
$stubs = __DIR__ . '/../vendor-bin/nextcloud-ocp/vendor';
$loader->addPsr4('OCP\\', $stubs . '/nextcloud/ocp/OCP/');
$loader->addPsr4('Psr\\EventDispatcher\\', $stubs . '/psr/event-dispatcher/src/');
$loader->addPsr4('Psr\\Http\\Message\\', $stubs . '/psr/http-message/src/');
$loader->addPsr4('Psr\\Http\\Client\\', $stubs . '/psr/http-client/src/');
$loader->addPsr4('Psr\\Container\\', $stubs . '/psr/container/src/');
$loader->addPsr4('Psr\\Log\\', $stubs . '/psr/log/src/');
$loader->addPsr4('Psr\\Clock\\', $stubs . '/psr/clock/src/');
