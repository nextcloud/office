<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Controller;

use OCA\Office\Controller\WopiController;
use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiLock;
use OCA\Office\Db\WopiLockMapper;
use OCA\Office\Db\WopiMapper;
use OCA\Office\Exception\ExpiredTokenException;
use OCA\Office\Exception\UnknownTokenException;
use OCA\Office\Service\WopiAccessResolver;
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILockManager;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WopiControllerLockTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private WopiMapper&MockObject $wopiMapper;
	private WopiLockMapper&MockObject $wopiLockMapper;
	private IUserManager&MockObject $userManager;
	private ILockManager&MockObject $lockManager;
	private LoggerInterface&MockObject $logger;
	private IShareManager&MockObject $shareManager;
	/** @var list<File> What the editor's user folder returns for the token's file id. */
	private array $userFolderNodes = [];
	private ?string $lookedUpUid = null;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->wopiMapper = $this->createMock(WopiMapper::class);
		$this->wopiLockMapper = $this->createMock(WopiLockMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->lockManager = $this->createMock(ILockManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->shareManager = $this->createMock(IShareManager::class);

		// Every user token resolves through the editor's folder; by default it holds an ordinary file.
		$this->userFolderNodes = [$this->fileForRename('report.docx')];
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturnCallback(fn (int $id): array => $id === 82 ? $this->userFolderNodes : []);
		$this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid) use ($userFolder): Folder {
			$this->lookedUpUid = $uid;
			return $userFolder;
		});

		// Default: simulate a lock provider that just runs the callback (as if
		// the app-level advisory lock was acquired without contention).
		$this->lockManager->method('runInScope')->willReturnCallback(
			static function ($lockContext, callable $callback): void {
				$callback();
			}
		);
	}

	/** @param array<string, string> $headers */
	private function controller(array $headers): WopiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $headers[$name] ?? ''
		);

		return new WopiController(
			'office',
			$request,
			new WopiAccessResolver($this->rootFolder, $this->shareManager),
			$this->wopiMapper,
			$this->wopiLockMapper,
			$this->userManager,
			$this->lockManager,
			$this->logger,
		);
	}

	/** @param array<string, mixed> $overrides */
	private function wopi(array $overrides = []): Wopi {
		$wopi = new Wopi();
		$wopi->setFileid($overrides['fileid'] ?? 82);
		$wopi->setCanwrite($overrides['canwrite'] ?? true);
		$wopi->setOwnerUid($overrides['ownerUid'] ?? 'admin');
		$wopi->setEditorUid(array_key_exists('editorUid', $overrides) ? $overrides['editorUid'] : 'admin');
		$wopi->setGuestDisplayname(array_key_exists('guestDisplayname', $overrides) ? $overrides['guestDisplayname'] : null);
		$wopi->setServerHost($overrides['serverHost'] ?? 'http://nc.local/');
		$wopi->setToken($overrides['token'] ?? 'tok123');
		$wopi->setHideDownload($overrides['hideDownload'] ?? false);

		return $wopi;
	}

	private function lock(string $lockId): WopiLock {
		$lock = new WopiLock();
		$lock->setFileid(82);
		$lock->setLockId($lockId);
		$lock->setExpiry(time() + 1800);

		return $lock;
	}

	private function expiredLock(string $lockId): WopiLock {
		$lock = new WopiLock();
		$lock->setFileid(82);
		$lock->setLockId($lockId);
		$lock->setExpiry(time() - 100);

		return $lock;
	}

	private function fileForRename(string $name): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);

		return $file;
	}

	private function mockFileLookup(File $file): void {
		$this->userFolderNodes = [$file];
	}

	private function linkShare(int $permissions, bool $hideDownload = false, ?\OCP\Files\Node $node = null): \OCP\Share\IShare&MockObject {
		$share = $this->createMock(\OCP\Share\IShare::class);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getHideDownload')->willReturn($hideDownload);
		$share->method('getNode')->willReturn($node ?? $this->sharedFile());

		return $share;
	}

	private function sharedFile(int $id = 82): File&MockObject {
		$file = $this->fileForRename('report.docx');
		$file->method('getId')->willReturn($id);
		$file->method('getMTime')->willReturn(1700000000);

		return $file;
	}

	/**
	 * Response::getHeaders() merges in framework defaults (request ID, CSP, the
	 * current user via \OCP\Server::get()), which needs a running Nextcloud
	 * server and isn't available standalone. Read the headers WopiController
	 * itself set via addHeader() directly off the private property instead.
	 */
	private function header(Http\Response $response, string $name): ?string {
		$headers = (new \ReflectionProperty(Http\Response::class, 'headers'))->getValue($response);

		return $headers[$name] ?? null;
	}

	// --- CheckFileInfo lock capability ---

	public function testCheckFileInfoAdvertisesLocksWithoutAnNcLockProvider(): void {
		$this->lockManager->method('isLockProviderAvailable')->willReturn(false);
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$file = $this->fileForRename('report.docx');
		$file->method('getSize')->willReturn(1024);
		$file->method('getMTime')->willReturn(1700000000);
		$this->mockFileLookup($file);

		$response = $this->controller([])->checkFileInfo(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['SupportsLocks']);
	}

	// --- PutFile out-of-band change detection ---

	private const int MTIME = 1700000000;
	private const string MTIME_ISO = '2023-11-14T22:13:20.000Z';
	private const string STALE_ISO = '2023-11-14T22:00:00.000Z';
	private const int SAVED_MTIME = 1700000060;

	/** The mock's mtime moves to SAVED_MTIME once putContent() runs, like a real write. */
	private function lockedFileForPut(bool $expectWrite): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('free_space')->willReturn(-1);

		$mtime = self::MTIME;
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(1024);
		$file->method('getMTime')->willReturnCallback(static function () use (&$mtime): int {
			return $mtime;
		});
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/report.docx');
		$file->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$file->expects($expectWrite ? $this->once() : $this->never())
			->method('putContent')
			->willReturnCallback(static function () use (&$mtime): void {
				$mtime = self::SAVED_MTIME;
			});

		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('eo-lock'));
		$this->mockFileLookup($file);

		return $file;
	}

	/** @param array<string, string> $headers */
	private function putFile(array $headers): Http\Response {
		return $this->controller(['X-WOPI-Lock' => 'eo-lock'] + $headers)->putFile(82, 'tok123');
	}

	public function testPutFileRejectsStaleCollaboraTimestamp(): void {
		$this->lockedFileForPut(expectWrite: false);

		$response = $this->putFile(['X-COOL-WOPI-Timestamp' => self::STALE_ISO]);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['COOLStatusCode' => 1010], $response->getData());
	}

	public function testPutFileWritesStaleEuroOfficeSaveAndLogsTheOverwrite(): void {
		// Euro-Office can't recover from a 409 here: it would fail every later save of the session.
		$this->lockedFileForPut(expectWrite: true);
		$this->logger->expects($this->once())->method('warning');

		$response = $this->putFile(['X-LOOL-WOPI-Timestamp' => self::STALE_ISO]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCollaboraTimestampDecidesWhenBothAreSent(): void {
		$this->lockedFileForPut(expectWrite: false);

		$response = $this->putFile(['X-COOL-WOPI-Timestamp' => self::STALE_ISO, 'X-LOOL-WOPI-Timestamp' => self::MTIME_ISO]);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	/** @return array<string, array{string, string}> */
	public static function currentTimestamps(): array {
		return [
			'Euro-Office' => ['X-LOOL-WOPI-Timestamp', self::MTIME_ISO],
			'Collabora' => ['X-COOL-WOPI-Timestamp', self::MTIME_ISO],
			'Collabora, microsecond format' => ['X-COOL-WOPI-Timestamp', '2023-11-14T22:13:20.000000Z'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('currentTimestamps')]
	public function testPutFileWritesWhenEditorTimestampIsCurrent(string $header, string $timestamp): void {
		$this->lockedFileForPut(expectWrite: true);
		$this->logger->expects($this->never())->method('warning');

		$response = $this->putFile([$header => $timestamp]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testPutFileReturnsTheTimestampOfTheNewContent(): void {
		// Editors take their next X-*-WOPI-Timestamp from this response; returning the pre-write mtime
		// would make the next save look stale.
		$this->lockedFileForPut(expectWrite: true);

		$response = $this->putFile(['X-LOOL-WOPI-Timestamp' => self::MTIME_ISO]);

		$this->assertSame(['LastModifiedTime' => '2023-11-14T22:14:20.000Z'], $response->getData());
		$this->assertSame((string)self::SAVED_MTIME, $this->header($response, 'X-WOPI-ItemVersion'));
	}

	public function testUnreadableRequestBodyLeavesTheDocumentAlone(): void {
		$this->lockedFileForPut(expectWrite: false);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'X-WOPI-Lock' ? 'eo-lock' : '');
		$controller = new class('office', $request, new WopiAccessResolver($this->rootFolder, $this->shareManager), $this->wopiMapper, $this->wopiLockMapper, $this->userManager, $this->lockManager, $this->logger) extends WopiController {
			#[\Override]
			protected function openRequestBody() {
				return false;
			}
		};

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $controller->putFile(82, 'tok123')->getStatus());
	}

	public function testPutFileWithoutTimestampHeaderStillWrites(): void {
		$this->lockedFileForPut(expectWrite: true);

		$this->assertSame(Http::STATUS_OK, $this->putFile([])->getStatus());
	}

	// --- Share-link sessions: access follows the live share ---

	private function linkSession(?string $editorUid = null): Wopi {
		$wopi = $this->wopi(['editorUid' => $editorUid, 'guestDisplayname' => 'Visitor']);
		$wopi->setShareToken('shareTok');
		$this->wopiMapper->method('getWopiForToken')->willReturn($wopi);

		return $wopi;
	}

	private function shareIsGone(): void {
		$this->shareManager->method('getShareByToken')->willThrowException(new \OCP\Share\Exceptions\ShareNotFound());
	}

	public function testRevokedShareEndsCheckFileInfo(): void {
		$this->linkSession();
		$this->shareIsGone();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller([])->checkFileInfo(82, 'tok123')->getStatus());
	}

	public function testRevokedShareEndsGetFile(): void {
		$this->linkSession();
		$this->shareIsGone();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller([])->getFile(82, 'tok123')->getStatus());
	}

	public function testRevokedShareEndsPutFile(): void {
		$this->linkSession();
		$this->shareIsGone();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['X-WOPI-Lock' => 'eo-lock'])->putFile(82, 'tok123')->getStatus());
	}

	public function testRevokedShareEndsLockOperations(): void {
		$this->linkSession();
		$this->shareIsGone();

		$response = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'eo-lock'])->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testShareNarrowedToReadOnlyBlocksPutFile(): void {
		$this->linkSession();
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(['X-WOPI-Lock' => 'eo-lock'])->putFile(82, 'tok123')->getStatus());
	}

	public function testShareNarrowedToReadOnlyBlocksLockOperations(): void {
		$this->linkSession();
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ));

		$response = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'eo-lock'])->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testShareNarrowedToReadOnlyShowsAsReadOnlyInCheckFileInfo(): void {
		$this->linkSession();
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ, hideDownload: true));

		$data = $this->controller([])->checkFileInfo(82, 'tok123')->getData();

		$this->assertFalse($data['UserCanWrite']);
		$this->assertTrue($data['DisableExport']);
	}

	public function testLoggedInLinkVisitorIsNamedButCannotRenameOrReshare(): void {
		$this->linkSession('bob');
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE));

		$data = $this->controller([])->checkFileInfo(82, 'tok123')->getData();

		$this->assertSame('bob', $data['UserId']);
		$this->assertFalse($data['IsAnonymousUser']);
		$this->assertFalse($data['SupportsRename']);
		$this->assertFalse($data['UserCanRename']);
		$this->assertFalse($data['EnableShare']);
		$this->assertNull($this->lookedUpUid, 'a link session must not resolve through the visitor\'s own folder');
	}

	public function testLoggedInLinkVisitorCannotRenameTheFile(): void {
		$this->linkSession('bob');
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE));

		$response = $this->controller(['X-WOPI-Override' => 'RENAME_FILE', 'X-WOPI-RequestedName' => 'Renamed'])->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAnonymousLinkVisitorIsAnonymous(): void {
		$this->linkSession();
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ));

		$data = $this->controller([])->checkFileInfo(82, 'tok123')->getData();

		$this->assertTrue($data['IsAnonymousUser']);
		$this->assertSame('Visitor', $data['UserFriendlyName']);
		$this->assertStringStartsWith('Guest-', $data['UserId']);
	}

	// --- executeOperation() dispatch guard ---

	public function testAllOverridesReturn403WhenTokenCannotWrite(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi(['canwrite' => false]));

		foreach (['LOCK', 'UNLOCK', 'REFRESH_LOCK', 'GET_LOCK', 'RENAME_FILE'] as $override) {
			$controller = $this->controller(['X-WOPI-Override' => $override, 'X-WOPI-Lock' => 'abc']);
			$response = $controller->executeOperation(82, 'tok123');
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), "override $override should be 403");
		}
	}

	public function testUnknownOverrideReturns501(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());

		$controller = $this->controller(['X-WOPI-Override' => 'SOMETHING_ELSE']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
	}

	public function testExecuteOperationReturns403WhenFileIdMismatch(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi(['fileid' => 82]));

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'abc']);
		$response = $controller->executeOperation(999, 'tok123');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testExecuteOperationReturns401WhenTokenExpired(): void {
		$this->wopiMapper->method('getWopiForToken')->willThrowException(new ExpiredTokenException('expired'));

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'abc']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testExecuteOperationReturns403WhenTokenUnknown(): void {
		$this->wopiMapper->method('getWopiForToken')->willThrowException(new UnknownTokenException('unknown'));

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'abc']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	// --- LOCK ---

	public function testLockEmptyIdReturns400(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => '']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testLockFreshReturns200(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);
		$this->wopiLockMapper->expects($this->once())->method('upsertLock')->with(82, 'lock-1');

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'lock-1']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLockSameIdIsIdempotent(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));
		$this->wopiLockMapper->expects($this->once())->method('upsertLock')->with(82, 'lock-1');

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'lock-1']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLockDifferentIdReturns409WithBothHeaders(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'lock-2']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('lock-1', $this->header($response, 'X-WOPI-Lock'));
		$this->assertNotEmpty($this->header($response, 'X-WOPI-LockFailureReason'));
	}

	public function testUnlockAndRelockValidReturns200(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('old-lock'));
		$this->wopiLockMapper->expects($this->once())->method('upsertLock')->with(82, 'new-lock');

		$controller = $this->controller([
			'X-WOPI-Override' => 'LOCK',
			'X-WOPI-Lock' => 'new-lock',
			'X-WOPI-OldLock' => 'old-lock',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUnlockAndRelockWrongOldReturns409(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('old-lock'));

		$controller = $this->controller([
			'X-WOPI-Override' => 'LOCK',
			'X-WOPI-Lock' => 'new-lock',
			'X-WOPI-OldLock' => 'wrong-old-lock',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('old-lock', $this->header($response, 'X-WOPI-Lock'));
	}

	public function testUnlockAndRelockWithNoExistingLockReturns409(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$controller = $this->controller([
			'X-WOPI-Override' => 'LOCK',
			'X-WOPI-Lock' => 'new-lock',
			'X-WOPI-OldLock' => 'old-lock',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('', $this->header($response, 'X-WOPI-Lock'));
	}

	public function testExpiredExistingLockTreatedAsAbsentOnLock(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->expiredLock('stale-lock'));
		$this->wopiLockMapper->expects($this->once())->method('upsertLock')->with(82, 'fresh-lock');

		$controller = $this->controller(['X-WOPI-Override' => 'LOCK', 'X-WOPI-Lock' => 'fresh-lock']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// --- UNLOCK ---

	public function testUnlockOkReturns200(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$lock = $this->lock('lock-1');
		$this->wopiLockMapper->method('findByFileId')->willReturn($lock);
		$this->wopiLockMapper->expects($this->once())->method('delete')->with($lock);

		$controller = $this->controller(['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'lock-1']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUnlockEmptyIdReturns400(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());

		$controller = $this->controller(['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => '']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUnlockWrongIdReturns409(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));

		$controller = $this->controller(['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'lock-2']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('lock-1', $this->header($response, 'X-WOPI-Lock'));
	}

	public function testUnlockWithNoExistingLockReturns409(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$controller = $this->controller(['X-WOPI-Override' => 'UNLOCK', 'X-WOPI-Lock' => 'lock-1']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('', $this->header($response, 'X-WOPI-Lock'));
	}

	// --- REFRESH_LOCK ---

	public function testRefreshLockOkReturns200(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));
		$this->wopiLockMapper->expects($this->once())->method('upsertLock')->with(82, 'lock-1');

		$controller = $this->controller(['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => 'lock-1']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testRefreshLockEmptyIdReturns400(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());

		$controller = $this->controller(['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => '']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRefreshLockMismatchReturns409(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));

		$controller = $this->controller(['X-WOPI-Override' => 'REFRESH_LOCK', 'X-WOPI-Lock' => 'lock-2']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	// --- GET_LOCK ---

	public function testGetLockWithLockReturnsLockHeader(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));

		$controller = $this->controller(['X-WOPI-Override' => 'GET_LOCK']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('lock-1', $this->header($response, 'X-WOPI-Lock'));
	}

	public function testGetLockWithoutLockReturnsEmptyHeader(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$controller = $this->controller(['X-WOPI-Override' => 'GET_LOCK']);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $this->header($response, 'X-WOPI-Lock'));
	}

	// --- RENAME_FILE ---

	public function testRenameFileSuccessReturns200WithName(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$originalFile = $this->fileForRename('Original.docx');
		$renamedFile = $this->fileForRename('Renamed.docx');
		$originalFile->method('move')->willReturn($renamedFile);

		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('Renamed.docx')->willThrowException(new NotFoundException());
		$parent->method('getPath')->willReturn('/admin/files');
		$originalFile->method('getParent')->willReturn($parent);

		$this->mockFileLookup($originalFile);

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => 'Renamed',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Renamed.docx', $response->getData()['Name']);
	}

	public function testRenameFileThroughShareLinkReturns403(): void {
		$wopi = $this->wopi(['editorUid' => null, 'guestDisplayname' => 'Guest User']);
		$wopi->setShareToken('shareTok');
		$this->wopiMapper->method('getWopiForToken')->willReturn($wopi);
		$this->shareManager->method('getShareByToken')->willReturn($this->linkShare(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE));

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => 'Renamed',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testRenameFileEmptyRequestedNameReturns400(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => '',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRenameFileRejectsPathTraversalSlashOrNullByte(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());

		foreach (['evil/name', "evil\0name"] as $maliciousName) {
			$controller = $this->controller([
				'X-WOPI-Override' => 'RENAME_FILE',
				'X-WOPI-RequestedName' => $maliciousName,
			]);
			$response = $controller->executeOperation(82, 'tok123');

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), "should reject: $maliciousName");
			$this->assertNotEmpty($this->header($response, 'X-WOPI-InvalidFileNameError'));
		}
	}

	public function testRenameFileRejectsBackslashTraversal(): void {
		// Fixed (was testRenameFileDoesNotRejectBackslashTraversal, documenting
		// a gap): basename() only treats '/' as a path separator on non-Windows
		// PHP builds, so a backslash-based name used to slip past the guard.
		// WopiController now checks str_contains($newBaseName, '\\') explicitly
		// alongside the slash/null-byte checks - see
		// wopi-spec-matrix.php row RENAME-backslash-not-rejected-by-basename-guard.
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => '..\\..\\evil',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertNotEmpty($this->header($response, 'X-WOPI-InvalidFileNameError'));
	}

	public function testRenameFileNameCollisionReturns400(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$originalFile = $this->fileForRename('Original.docx');
		$existing = $this->fileForRename('Renamed.docx');
		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('Renamed.docx')->willReturn($existing);
		$originalFile->method('getParent')->willReturn($parent);

		$this->mockFileLookup($originalFile);

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => 'Renamed',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertNotEmpty($this->header($response, 'X-WOPI-InvalidFileNameError'));
	}

	public function testRenameFileLockMismatchReturns409(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn($this->lock('lock-1'));

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => 'Renamed',
			'X-WOPI-Lock' => 'lock-2',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('lock-1', $this->header($response, 'X-WOPI-Lock'));
	}

	public function testRenameFileFileNotFoundReturns404(): void {
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$this->userFolderNodes = [];

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => 'Renamed',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
