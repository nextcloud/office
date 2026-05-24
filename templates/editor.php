<?php

declare(strict_types=1);

use OCA\Office\AppInfo\Application;
use OCP\Util;

/**
 * @var array $_ Template variables:
 *            - editorUrl       string  Full editor URL with wopisrc and access_token substituted
 *            - postMessageOrigin string NC server host used to validate inbound postMessages
 *            - fileName        string  Human-readable file name shown in the page title
 */

Util::addScript(Application::APP_ID, Application::APP_ID . '-editor');

?>
<script id="office-editor-data" type="application/json">
<?php echo json_encode([
	'editorUrl' => $_['editorUrl'],
	'postMessageOrigin' => $_['postMessageOrigin'],
	'fileName' => $_['fileName'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>
<div id="office-editor"></div>
