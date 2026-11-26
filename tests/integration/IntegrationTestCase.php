<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared fixture handling for the WOPI integration suite. Runs against a live
 * Nextcloud server (see tests/run-integration.sh) - this is NOT a mocked unit
 * test, every HTTP call hits the real WopiController. A StubEditor plays the
 * editor server: the app reads its discovery, and static::$http signs WOPI
 * requests with its proof key.
 */

namespace OCA\Office\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use OCA\Office\AppInfo\Application;
use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiMapper;
use OCA\Office\Service\DiscoveryService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

abstract class IntegrationTestCase extends TestCase {
	protected const TEST_USER = 'wopi_it_user';
	protected const TEST_PASSWORD = 'wopiIntegrationTest#2026!';
	// Settings that would point the app away from the stub editor or block the test client.
	private const EDITOR_SETTINGS = ['wopi_url', 'public_wopi_url', 'callback_url', 'wopi_allowlist'];

	protected static Client $http;
	protected static StubEditor $editor;
	protected static Folder $userFolder;
	private static ?StubEditor $runningEditor = null;

	public static function setUpBeforeClass(): void {
		static::$editor = self::stubEditor();
		$handler = HandlerStack::create();
		$handler->push(static::$editor->signingMiddleware());
		static::$http = new Client([
			'base_uri' => self::baseUrl(),
			'http_errors' => false,
			'handler' => $handler,
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

	/**
	 * One stub editor per process, set as the app's editor server; the settings it replaces
	 * are put back when the process ends.
	 */
	private static function stubEditor(): StubEditor {
		if (self::$runningEditor !== null) {
			return self::$runningEditor;
		}

		$editor = StubEditor::start();
		$appConfig = Server::get(IAppConfig::class);
		$saved = [];
		foreach (self::EDITOR_SETTINGS as $key) {
			$saved[$key] = $appConfig->hasKey(Application::APP_ID, $key) ? $appConfig->getValueString(Application::APP_ID, $key) : null;
			$appConfig->deleteKey(Application::APP_ID, $key);
		}
		$appConfig->setValueString(Application::APP_ID, 'wopi_url', $editor->url);
		Server::get(DiscoveryService::class)->resetCache();
		self::waitForWebServerConfig();

		register_shutdown_function(static function () use ($editor, $saved): void {
			$appConfig = Server::get(IAppConfig::class);
			foreach ($saved as $key => $value) {
				if ($value === null) {
					$appConfig->deleteKey(Application::APP_ID, $key);
				} else {
					$appConfig->setValueString(Application::APP_ID, $key, $value);
				}
			}
			Server::get(DiscoveryService::class)->resetCache();
			$editor->stop();
		});

		return self::$runningEditor = $editor;
	}

	/**
	 * With memcache.local the web server caches app config for up to 3 seconds
	 * (OC\AppConfig::LOCAL_CACHE_TTL), and this process can't clear that cache.
	 */
	private static function waitForWebServerConfig(): void {
		if (Server::get(IConfig::class)->getSystemValueString('memcache.local') !== '') {
			sleep(4);
		}
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
			hideDownload: false,
			serverHost: self::baseUrl(),
		);
	}

	/**
	 * The editor URL an /open response hands the browser.
	 */
	protected function editorUrlFrom(ResponseInterface $response): string {
		$this->assertSame(1, preg_match('#<script id="office-editor-data" type="application/json">\s*(.*?)\s*</script>#s', (string)$response->getBody(), $match));
		$data = json_decode($match[1], true);
		$this->assertIsArray($data);
		$this->assertIsString($data['editorUrl']);

		return $data['editorUrl'];
	}

	/**
	 * What the editor does with that URL first: CheckFileInfo on its wopisrc, with its token.
	 */
	protected function checkFileInfoFromEditorUrl(string $editorUrl): ResponseInterface {
		parse_str((string)parse_url($editorUrl, PHP_URL_QUERY), $query);
		$this->assertIsString($query['wopisrc'] ?? null);
		$this->assertIsString($query['access_token'] ?? null);

		return static::$http->get($query['wopisrc'] . '?access_token=' . urlencode($query['access_token']));
	}

	protected function wopiFilesUrl(int $fileId, string $token): string {
		return '/index.php/apps/office/wopi/files/' . $fileId . '?access_token=' . urlencode($token);
	}

	protected function wopiContentsUrl(int $fileId, string $token): string {
		return '/index.php/apps/office/wopi/files/' . $fileId . '/contents?access_token=' . urlencode($token);
	}
}
