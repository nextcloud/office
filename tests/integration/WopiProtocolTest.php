<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ports WOPI_TEST_RESULTS.md rows 1-18 (CheckFileInfo, GetFile, PutFile, the
 * lock protocol) plus RenameFile, which had no coverage anywhere before this
 * suite. Every request goes over real HTTP to a running Nextcloud + this app.
 */

namespace OCA\Office\Tests\Integration;

class WopiProtocolTest extends IntegrationTestCase {
	public function testCheckFileInfoReturnsMetadataForValidToken(): void {
		$file = $this->createTestFile('checkfileinfo.docx');
		$wopi = $this->generateToken($file);

		$response = static::$http->get($this->wopiFilesUrl($file->getId(), $wopi->getToken()));

		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode((string)$response->getBody(), true);
		$this->assertSame('checkfileinfo.docx', $body['BaseFileName']);
		$this->assertSame(static::TEST_USER, $body['UserId']);
		$this->assertTrue($body['UserCanWrite']);
		$this->assertFalse($body['IsAnonymousUser']);
	}

	public function testCheckFileInfoReturns403ForUnknownToken(): void {
		$file = $this->createTestFile('checkfileinfo-unknown.docx');

		$response = static::$http->get($this->wopiFilesUrl($file->getId(), 'not-a-real-token'));

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testGetFileReturnsBinaryBody(): void {
		$file = $this->createTestFile('getfile.docx', 'exact file content for GetFile');
		$wopi = $this->generateToken($file);

		$response = static::$http->get($this->wopiContentsUrl($file->getId(), $wopi->getToken()));

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('exact file content for GetFile', (string)$response->getBody());
		$this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
	}

	public function testGetFileRangeReturnsPartialContent(): void {
		$content = str_repeat('0123456789', 20); // 200 bytes
		$file = $this->createTestFile('getfile-range.docx', $content);
		$wopi = $this->generateToken($file);

		$response = static::$http->get($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'headers' => ['Range' => 'bytes=0-99'],
		]);

		$this->assertSame(206, $response->getStatusCode());
		$this->assertSame('bytes 0-99/200', $response->getHeaderLine('Content-Range'));
		$this->assertSame(substr($content, 0, 100), (string)$response->getBody());
	}

	public function testGetFileRangeBeyondEofReturns416(): void {
		$content = str_repeat('a', 50);
		$file = $this->createTestFile('getfile-416.docx', $content);
		$wopi = $this->generateToken($file);

		$response = static::$http->get($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'headers' => ['Range' => 'bytes=99999-'],
		]);

		$this->assertSame(416, $response->getStatusCode());
		$this->assertSame('bytes */50', $response->getHeaderLine('Content-Range'));
	}

	/**
	 * WOPI_TEST_RESULTS.md row 5 documented "PutFile, no lock held -> 200"
	 * without noting file size. Verified live in this environment: that only
	 * holds for an EMPTY file - see testPutFileOnNonEmptyFileWithNoLockReturns409
	 * below for the H-3 fix (commit 71e4f08) that now requires a lock for any
	 * non-empty save.
	 */
	public function testPutFileOnEmptyFileWithNoLockSucceeds(): void {
		$file = $this->createTestFile('putfile-empty.docx', '');
		$wopi = $this->generateToken($file);

		$response = static::$http->post($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'body' => 'first content, file was empty',
		]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testPutFileOnNonEmptyFileWithNoLockReturns409(): void {
		$file = $this->createTestFile('putfile-nonempty.docx', 'not empty');
		$wopi = $this->generateToken($file);

		$response = static::$http->post($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'body' => 'attempted overwrite without a lock',
		]);

		$this->assertSame(409, $response->getStatusCode());
		$this->assertSame('', $response->getHeaderLine('X-WOPI-Lock'));
		$this->assertNotEmpty($response->getHeaderLine('X-WOPI-LockFailureReason'));
	}

	public function testPutFileWithCorrectLockSucceeds(): void {
		$file = $this->createTestFile('putfile-locked.docx', 'not empty');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		$lockResponse = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'put-lock']]);
		$this->assertSame(200, $lockResponse->getStatusCode());

		$response = static::$http->post($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'headers' => ['X-WOPI-Lock' => 'put-lock'],
			'body' => 'new content, correctly locked',
		]);

		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode((string)$response->getBody(), true);
		$this->assertArrayHasKey('LastModifiedTime', $body);
	}

	public function testPutFileWithoutMatchingLockReturns409(): void {
		$file = $this->createTestFile('putfile-wronglock.docx', 'not empty');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'holder-lock']]);

		$response = static::$http->post($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'headers' => ['X-WOPI-Lock' => 'wrong-lock'],
			'body' => 'should be rejected',
		]);

		$this->assertSame(409, $response->getStatusCode());
		$this->assertSame('holder-lock', $response->getHeaderLine('X-WOPI-Lock'));
	}

	public function testPutFileWithStaleItemVersionReturns409(): void {
		$file = $this->createTestFile('putfile-itemversion.docx', 'not empty');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'version-lock']]);

		$response = static::$http->post($this->wopiContentsUrl($file->getId(), $wopi->getToken()), [
			'headers' => ['X-WOPI-Lock' => 'version-lock', 'X-WOPI-ItemVersion' => '111111111'],
			'body' => 'stale version write attempt',
		]);

		$this->assertSame(409, $response->getStatusCode());
		$this->assertNotEmpty($response->getHeaderLine('X-WOPI-ItemVersion'));
	}

	public function testLockNewFileReturns200(): void {
		$file = $this->createTestFile('lock-new.docx');
		$wopi = $this->generateToken($file);

		$response = static::$http->post($this->wopiFilesUrl($file->getId(), $wopi->getToken()), [
			'headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'lock-1'],
		]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testGetLockReturnsLockId(): void {
		$file = $this->createTestFile('get-lock.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'lock-2']]);
		$response = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'GET_LOCK']]);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('lock-2', $response->getHeaderLine('X-WOPI-Lock'));
	}

	public function testLockDifferentIdReturns409(): void {
		$file = $this->createTestFile('lock-conflict.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'holder']]);
		$response = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'challenger']]);

		$this->assertSame(409, $response->getStatusCode());
		$this->assertSame('holder', $response->getHeaderLine('X-WOPI-Lock'));
		$this->assertNotEmpty($response->getHeaderLine('X-WOPI-LockFailureReason'));
	}

	public function testLockSameIdIsIdempotent(): void {
		$file = $this->createTestFile('lock-idempotent.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'same-id']]);
		$response = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'same-id']]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testRefreshLockReturns200(): void {
		$file = $this->createTestFile('refresh-lock.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'refresh-me']]);
		$response = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => 'refresh-me']]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testUnlockReturns200(): void {
		$file = $this->createTestFile('unlock.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'to-unlock']]);
		$response = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'to-unlock']]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testGetLockAfterUnlockReturnsEmptyHeader(): void {
		$file = $this->createTestFile('get-lock-after-unlock.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'temp']]);
		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'temp']]);
		$response = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'GET_LOCK']]);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('', $response->getHeaderLine('X-WOPI-Lock'));
	}

	public function testUnlockAndRelockValid(): void {
		$file = $this->createTestFile('unlockandrelock.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'old']]);
		$response = static::$http->post($filesUrl, [
			'headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'new', 'X-WOPI-OldLock' => 'old'],
		]);

		$this->assertSame(200, $response->getStatusCode());

		$getLock = static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'GET_LOCK']]);
		$this->assertSame('new', $getLock->getHeaderLine('X-WOPI-Lock'));
	}

	public function testUnlockAndRelockWrongOldReturns409(): void {
		$file = $this->createTestFile('unlockandrelock-wrong.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'actual-old']]);
		$response = static::$http->post($filesUrl, [
			'headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'new', 'X-WOPI-OldLock' => 'wrong-old'],
		]);

		$this->assertSame(409, $response->getStatusCode());
		$this->assertSame('actual-old', $response->getHeaderLine('X-WOPI-Lock'));
	}

	public function testRenameFileSucceeds(): void {
		$file = $this->createTestFile('rename-me.docx');
		$wopi = $this->generateToken($file);
		$filesUrl = $this->wopiFilesUrl($file->getId(), $wopi->getToken());

		static::$http->post($filesUrl, ['headers' => ['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'rename-lock']]);
		$response = static::$http->post($filesUrl, [
			'headers' => [
				'X-WOPI-Override' => 'RENAME_FILE',
				'X-WOPI-RequestedName' => 'renamed-integration-file',
				'X-WOPI-Lock' => 'rename-lock',
			],
		]);

		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode((string)$response->getBody(), true);
		$this->assertSame('renamed-integration-file.docx', $body['Name']);
	}
}
