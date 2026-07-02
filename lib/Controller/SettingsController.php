<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Controller;

use OCA\Office\AppInfo\Application;
use OCA\Office\Service\DiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private DiscoveryService $discoveryService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return the current admin settings.
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Office\Settings\Admin::class)]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/settings/admin')]
	public function getAdmin(): DataResponse {
		return new DataResponse([
			'wopi_url' => $this->appConfig->getValueString(Application::APP_ID, 'wopi_url', ''),
			'public_wopi_url' => $this->appConfig->getValueString(Application::APP_ID, 'public_wopi_url', ''),
			'callback_url' => $this->appConfig->getValueString(Application::APP_ID, 'callback_url', ''),
			'disable_certificate_verification' => $this->appConfig->getValueString(Application::APP_ID, 'disable_certificate_verification', 'no'),
		]);
	}

	/**
	 * Persist admin settings.
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Office\Settings\Admin::class)]
	#[FrontpageRoute(verb: 'POST', url: '/settings/admin')]
	public function setAdmin(string $wopi_url, string $public_wopi_url = '', string $callback_url = '', string $disable_certificate_verification = 'no'): DataResponse {
		foreach (['wopi_url' => $wopi_url, 'public_wopi_url' => $public_wopi_url, 'callback_url' => $callback_url] as $field => $value) {
			if ($value !== '') {
				$parsed = parse_url($value);
				if ($parsed === false || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
					return new DataResponse(['error' => "$field must use the http or https scheme"], Http::STATUS_BAD_REQUEST);
				}
				if (isset($parsed['user']) || isset($parsed['pass'])) {
					return new DataResponse(['error' => "$field must not contain credentials"], Http::STATUS_BAD_REQUEST);
				}
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, 'wopi_url', rtrim($wopi_url, '/'));
		$this->appConfig->setValueString(Application::APP_ID, 'public_wopi_url', rtrim($public_wopi_url, '/'));
		$this->appConfig->setValueString(Application::APP_ID, 'callback_url', rtrim($callback_url, '/'));
		$this->appConfig->setValueString(Application::APP_ID, 'disable_certificate_verification', $disable_certificate_verification === 'yes' ? 'yes' : 'no');

		// The discovery document depends on wopi_url; a settings change must not
		// serve actions cached from the previous editor server for up to CACHE_TTL.
		$this->discoveryService->resetCache();

		return new DataResponse([]);
	}
}
