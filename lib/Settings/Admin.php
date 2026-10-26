<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Settings;

use OCA\Office\AppInfo\Application;
use OCA\Office\Service\DiscoveryService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private DiscoveryService $discoveryService,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'wopi_url' => $this->appConfig->getValueString(Application::APP_ID, 'wopi_url', ''),
			'public_wopi_url' => $this->appConfig->getValueString(Application::APP_ID, 'public_wopi_url', ''),
			'callback_url' => $this->appConfig->getValueString(Application::APP_ID, 'callback_url', ''),
			'disable_certificate_verification' => $this->appConfig->getValueString(Application::APP_ID, 'disable_certificate_verification', 'no'),
			// Warn when nothing checks who calls the WOPI endpoints: the editor publishes no
			// proof keys and no wopi_allowlist is set. Unknown (null) while discovery is unreachable.
			'wopi_caller_unchecked' => $this->discoveryService->isAvailable()
				? $this->discoveryService->getProofKeys() === null
					&& trim($this->appConfig->getValueString(Application::APP_ID, 'wopi_allowlist', '')) === ''
				: null,
		]);
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 50;
	}
}
