<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ports WOPI_TEST_RESULTS.md row 21. Discovery comes from the StubEditor, which
 * advertises an edit action for docx only.
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

	public function testOpenHandsTheEditorAUrlItCanOpenTheFileWith(): void {
		$file = $this->createTestFile('editor-open.docx');

		$response = static::$http->get('/index.php/apps/office/open?fileId=' . $file->getId(), [
			'auth' => [static::TEST_USER, static::TEST_PASSWORD],
		]);

		$this->assertSame(200, $response->getStatusCode());
		$editorUrl = $this->editorUrlFrom($response);
		$this->assertStringStartsWith(static::$editor->url . '/hosting/wopi/word/edit?wopisrc=', $editorUrl);
		$this->assertStringContainsString(static::$editor->url, $response->getHeaderLine('Content-Security-Policy'));

		$checkFileInfo = $this->checkFileInfoFromEditorUrl($editorUrl);
		$this->assertSame(200, $checkFileInfo->getStatusCode());
		$body = json_decode((string)$checkFileInfo->getBody(), true);
		$this->assertSame('editor-open.docx', $body['BaseFileName']);
		$this->assertSame(static::TEST_USER, $body['UserId']);
	}

	public function testOpenReturns415ForAFileTypeTheEditorDoesNotHandle(): void {
		$file = $this->createTestFile('editor-open.txt');

		$response = static::$http->get('/index.php/apps/office/open?fileId=' . $file->getId(), [
			'auth' => [static::TEST_USER, static::TEST_PASSWORD],
		]);

		$this->assertSame(415, $response->getStatusCode());
	}
}
