<?php

declare(strict_types=1);

use OCA\Office\AppInfo\Application;
use OCP\Util;

/**
 * @var array $_ Template variables:
 *            - wopi_url                      string  Editor server base URL
 *            - disable_certificate_verification string  'yes' or 'no'
 */

Util::addScript(Application::APP_ID, Application::APP_ID . '-settings-admin');

?>
<script id="office-admin-data" type="application/json">
<?php echo json_encode([
	'wopi_url' => $_['wopi_url'],
	'disable_certificate_verification' => $_['disable_certificate_verification'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
<div id="office-settings-admin"></div>
