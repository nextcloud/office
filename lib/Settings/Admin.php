<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Settings;

use OCA\Office\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'wopi_url' => $this->appConfig->getValueString(Application::APP_ID, 'wopi_url', ''),
			'disable_certificate_verification' => $this->appConfig->getValueString(Application::APP_ID, 'disable_certificate_verification', 'no'),
		]);
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 50;
	}
}
