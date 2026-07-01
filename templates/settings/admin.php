<?php

declare(strict_types=1);

use OCA\Office\AppInfo\Application;
use OCP\Util;

/**
 * @var array $_ Template variables:
 *            - wopi_url                      string  Editor server base URL (internal, used server-side)
 *            - public_wopi_url               string  Editor server URL reachable by browsers (optional)
 *            - callback_url                  string  Nextcloud URL reachable by the editor server (optional)
 *            - disable_certificate_verification string  'yes' or 'no'
 */

Util::addScript(Application::APP_ID, Application::APP_ID . '-settings-admin');

?>
<script id="office-admin-data" type="application/json">
<?php echo json_encode([
	'wopi_url' => $_['wopi_url'],
	'public_wopi_url' => $_['public_wopi_url'],
	'callback_url' => $_['callback_url'],
	'disable_certificate_verification' => $_['disable_certificate_verification'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
<div id="office-settings-admin"></div>
