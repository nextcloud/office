<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Controller;

use OCA\Office\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return the current admin settings.
	 */
	#[AuthorizedAdminSetting(settings: Settings\Admin::class)]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/settings/admin')]
	public function getAdmin(): DataResponse {
		return new DataResponse([
			'wopi_url' => $this->appConfig->getValueString(Application::APP_ID, 'wopi_url', ''),
			'disable_certificate_verification' => $this->appConfig->getValueString(Application::APP_ID, 'disable_certificate_verification', 'no'),
		]);
	}

	/**
	 * Persist admin settings.
	 */
	#[AuthorizedAdminSetting(settings: Settings\Admin::class)]
	#[ApiRoute(verb: 'POST', url: '/settings/admin')]
	public function setAdmin(string $wopi_url, string $disable_certificate_verification = 'no'): DataResponse {
		if ($wopi_url !== '') {
			$parsed = parse_url($wopi_url);
			if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
				return new DataResponse(['error' => 'wopi_url must use the http or https scheme'], Http::STATUS_BAD_REQUEST);
			}
			if (isset($parsed['user']) || isset($parsed['pass'])) {
				return new DataResponse(['error' => 'wopi_url must not contain credentials'], Http::STATUS_BAD_REQUEST);
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, 'wopi_url', rtrim($wopi_url, '/'));
		$this->appConfig->setValueString(Application::APP_ID, 'disable_certificate_verification', $disable_certificate_verification === 'yes' ? 'yes' : 'no');

		return new DataResponse([]);
	}
}
