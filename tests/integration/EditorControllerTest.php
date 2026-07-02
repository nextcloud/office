<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ports WOPI_TEST_RESULTS.md row 21, with a caveat verified live in this
 * environment: EO currently advertises ZERO editor actions (wopi.enable=false
 * server-side - see plan "Known pitfalls" #6), so DiscoveryService::getUrlSrc()
 * returns null for every extension and /open always answers 415, never the
 * historical 200 + editorUrl. That is an EO-configuration fact, not a
 * regression in this app - do NOT change this test to expect 200; it would
 * only pass by accident once EO's discovery advertises a docx action, which
 * this suite has no control over. The 200 path is covered at the unit level
 * instead: DiscoveryServiceTest fixtures exercise getUrlSrc() directly against
 * a discovery document that actually has actions.
 */

namespace OCA\Office\Tests\Integration;

class EditorControllerTest extends IntegrationTestCase {
	public function testOpenReturns401WithoutAuth(): void {
		$response = static::$http->get('/index.php/apps/office/open?fileId=1');

		$this->assertSame(401, $response->getStatusCode());
	}

	public function testOpenReturns404ForNonexistentFile(): void {
		$response = static::$http->get('/index.php/apps/office/open?fileId=999999999', [
			'auth' => [static::TEST_USER, static::TEST_PASSWORD],
		]);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testOpenReturns415GivenEosCurrentZeroActionDiscovery(): void {
		$file = $this->createTestFile('editor-open.docx');

		$response = static::$http->get('/index.php/apps/office/open?fileId=' . $file->getId(), [
			'auth' => [static::TEST_USER, static::TEST_PASSWORD],
		]);

		$this->assertSame(415, $response->getStatusCode());
	}
}
