<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ports WOPI_TEST_RESULTS.md's unnumbered "Route / CleanupJob Verification"
 * check for the /index.php/ prefix (the CleanupJob registration half of that
 * section lives in CleanupJobTest::testCleanupJobIsRegisteredInJobList).
 */

namespace OCA\Office\Tests\Integration;

use OCP\IURLGenerator;
use OCP\Server;

class RouteTest extends IntegrationTestCase {
	public function testWopiRouteIncludesIndexPhpPrefix(): void {
		$urlGenerator = Server::get(IURLGenerator::class);

		$path = $urlGenerator->linkToRoute('office.wopi.checkFileInfo', ['fileId' => 1]);

		$this->assertStringStartsWith('/index.php/apps/office/wopi/files/', $path);
	}

	public function testGeneratedRouteIsReachableOverHttp(): void {
		$file = $this->createTestFile('route-check.docx');
		$wopi = $this->generateToken($file);
		$urlGenerator = Server::get(IURLGenerator::class);

		$path = $urlGenerator->linkToRoute('office.wopi.checkFileInfo', ['fileId' => $file->getId()]);
		$response = static::$http->get($path . '?access_token=' . urlencode($wopi->getToken()));

		$this->assertSame(200, $response->getStatusCode());
	}
}
