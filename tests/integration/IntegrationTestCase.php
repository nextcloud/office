<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared fixture handling for the WOPI integration suite. Runs in-container
 * against a live Nextcloud server (see tests/run-integration.sh) - this is
 * NOT a mocked unit test, every HTTP call hits the real WopiController.
 */

namespace OCA\Office\Tests\Integration;

use GuzzleHttp\Client;
use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase {
	protected const TEST_USER = 'wopi_it_user';
	protected const TEST_PASSWORD = 'wopiIntegrationTest#2026!';

	protected static Client $http;
	protected static Folder $userFolder;

	public static function setUpBeforeClass(): void {
		static::$http = new Client([
			'base_uri' => self::baseUrl(),
			'http_errors' => false,
		]);

		$userManager = Server::get(IUserManager::class);
		if (!$userManager->userExists(static::TEST_USER)) {
			$userManager->createUser(static::TEST_USER, static::TEST_PASSWORD);
		}

		static::$userFolder = Server::get(IRootFolder::class)->getUserFolder(static::TEST_USER);
	}

	public static function tearDownAfterClass(): void {
		// Deleting the user removes its home storage (and every file created
		// against static::$userFolder) - no separate per-file cleanup needed.
		Server::get(IUserManager::class)->get(static::TEST_USER)?->delete();
	}

	protected static function baseUrl(): string {
		$env = getenv('OFFICE_TEST_BASE_URL');

		return $env !== false && $env !== '' ? $env : 'http://localhost';
	}

	protected function createTestFile(string $name, string $content = "WOPI integration test content.\n"): File {
		if (static::$userFolder->nodeExists($name)) {
			static::$userFolder->get($name)->delete();
		}

		return static::$userFolder->newFile($name, $content);
	}

	protected function generateToken(File $file, bool $canWrite = true): Wopi {
		$wopiMapper = Server::get(WopiMapper::class);

		return $wopiMapper->generateFileToken(
			fileId: $file->getId(),
			ownerUid: static::TEST_USER,
			editorUid: static::TEST_USER,
			version: (string)$file->getMTime(),
			canWrite: $canWrite,
			serverHost: self::baseUrl(),
		);
	}

	protected function wopiFilesUrl(int $fileId, string $token): string {
		return '/index.php/apps/office/wopi/files/' . $fileId . '?access_token=' . urlencode($token);
	}

	protected function wopiContentsUrl(int $fileId, string $token): string {
		return '/index.php/apps/office/wopi/files/' . $fileId . '/contents?access_token=' . urlencode($token);
	}
}
