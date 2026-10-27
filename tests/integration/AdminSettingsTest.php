<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ports WOPI_TEST_RESULTS.md rows 19-20. Note the route itself changed since
 * that file was written: rows 19/20 documented /ocs/v2.php/apps/office/... but
 * commit eebb6aa moved admin settings to FrontpageRoute
 * (/index.php/apps/office/settings/admin) - see "Known pitfalls" #1 in the
 * plan. Tests here hit the CURRENT route.
 */

namespace OCA\Office\Tests\Integration;

class AdminSettingsTest extends IntegrationTestCase {
	private const ADMIN_URL = '/index.php/apps/office/settings/admin';

	public function testGetAdminSettingsReturnsConfigForAdmin(): void {
		$response = static::$http->get(self::ADMIN_URL, ['auth' => ['admin', 'admin']]);

		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode((string)$response->getBody(), true);
		$this->assertArrayHasKey('wopi_url', $body);
	}

	public function testGetAdminSettingsReturns401WithoutAuth(): void {
		$response = static::$http->get(self::ADMIN_URL);

		$this->assertSame(401, $response->getStatusCode());
	}

	public function testGetAdminSettingsReturns403ForNonAdminUser(): void {
		$response = static::$http->get(self::ADMIN_URL, [
			'auth' => [static::TEST_USER, static::TEST_PASSWORD],
		]);

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testPostAdminSettingsRejectsInvalidScheme(): void {
		$response = static::$http->post(self::ADMIN_URL, [
			'auth' => ['admin', 'admin'],
			'headers' => ['OCS-APIREQUEST' => 'true'],
			'form_params' => ['wopi_url' => 'file:///etc/passwd'],
		]);

		$this->assertSame(400, $response->getStatusCode());
	}

	public function testPostAdminSettingsRejectsCredentialsInUrl(): void {
		$response = static::$http->post(self::ADMIN_URL, [
			'auth' => ['admin', 'admin'],
			'headers' => ['OCS-APIREQUEST' => 'true'],
			'form_params' => ['wopi_url' => 'http://user:pass@eo'],
		]);

		$this->assertSame(400, $response->getStatusCode());
	}
}
