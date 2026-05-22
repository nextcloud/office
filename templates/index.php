<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Office\AppInfo\Application::APP_ID, OCA\Office\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Office\AppInfo\Application::APP_ID, OCA\Office\AppInfo\Application::APP_ID . '-main');

?>

<div id="office"></div>
