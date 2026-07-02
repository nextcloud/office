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
use OCP\AppFramework\Http;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Lock\ILockManager;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserManager;
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

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->wopiMapper = $this->createMock(WopiMapper::class);
		$this->wopiLockMapper = $this->createMock(WopiLockMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->lockManager = $this->createMock(ILockManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

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
			$this->rootFolder,
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

	private function mockFileLookup(File $file, int $fileid = 82, string $uid = 'admin'): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with($fileid)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with($uid)->willReturn($userFolder);
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

	public function testRenameFileGuestReturns403(): void {
		$wopi = $this->wopi(['editorUid' => null, 'guestDisplayname' => 'Guest User']);
		$this->wopiMapper->method('getWopiForToken')->willReturn($wopi);

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

	public function testRenameFileDoesNotRejectBackslashTraversal(): void {
		// Documenting current behavior, NOT asserting it is safe: basename() only
		// treats '/' as a path separator on non-Windows PHP builds (verified:
		// basename('..\..\evil.docx') === '..\..\evil.docx' on this build), so
		// the guard in testRenameFileRejectsPathTraversalSlashOrNullByte() above
		// does not catch a backslash-based name. Whether this is exploitable
		// depends on how Nextcloud's storage layer resolves the resulting path
		// (it splits on '/', not '\') - out of scope to fix here, see
		// wopi-spec-matrix.php row RENAME-backslash-not-rejected-by-basename-guard.
		$this->wopiMapper->method('getWopiForToken')->willReturn($this->wopi());
		$this->wopiLockMapper->method('findByFileId')->willReturn(null);

		$originalFile = $this->fileForRename('Original.docx');
		$renamedFile = $this->fileForRename('..\\..\\evil.docx');
		$originalFile->method('move')->willReturn($renamedFile);

		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willThrowException(new NotFoundException());
		$parent->method('getPath')->willReturn('/admin/files');
		$originalFile->method('getParent')->willReturn($parent);

		$this->mockFileLookup($originalFile);

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => '..\\..\\evil',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
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

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$controller = $this->controller([
			'X-WOPI-Override' => 'RENAME_FILE',
			'X-WOPI-RequestedName' => 'Renamed',
		]);
		$response = $controller->executeOperation(82, 'tok123');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
