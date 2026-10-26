<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Controller;

use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiLockMapper;
use OCA\Office\Db\WopiMapper;
use OCA\Office\Exception\ExpiredTokenException;
use OCA\Office\Exception\UnknownTokenException;
use OCA\Office\Service\WopiAccess;
use OCA\Office\Service\WopiAccessResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\NoLockProviderException;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;

class WopiController extends Controller {
	private const int COOL_STATUS_DOC_CHANGED = 1010;

	public function __construct(
		string $appName,
		IRequest $request,
		private WopiAccessResolver $accessResolver,
		private WopiMapper $wopiMapper,
		private WopiLockMapper $wopiLockMapper,
		private IUserManager $userManager,
		private ILockManager $lockManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * WOPI CheckFileInfo — returns metadata about the file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: 'wopi/files/{fileId}')]
	public function checkFileInfo(
		int $fileId,
		#[\SensitiveParameter]
		string $access_token,
	): JSONResponse {
		try {
			$wopi = $this->wopiMapper->getWopiForToken($access_token);
			$access = $this->accessResolver->resolve($wopi);
			$file = $access->file;
		} catch (UnknownTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (ExpiredTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		} catch (NotFoundException|NotPermittedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($wopi->getFileid() !== $fileId) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$isAnonymous = $wopi->isAnonymous();
		$isShareSession = $wopi->isShareSession();
		$user = $this->userManager->get($wopi->getEditorUid() ?? '');
		$displayName = $isAnonymous
			? ($wopi->getGuestDisplayname() ?? 'Guest')
			: ($user?->getDisplayName() ?? $wopi->getEditorUid() ?? '');

		$canWrite = $access->canWrite;

		try {
			$locks = $this->lockManager->getLocks($wopi->getFileid());
			foreach ($locks as $lock) {
				if ($lock->getType() === \OCP\Files\Lock\ILock::TYPE_USER && $lock->getOwner() !== $wopi->getEditorUid()) {
					$canWrite = false;
					break;
				}
			}
		} catch (NoLockProviderException|PreConditionNotMetException) {
		}

		$hideDownload = $access->hideDownload;

		return new JSONResponse([
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => (string)$file->getMTime(),
			'UserId' => $isAnonymous ? 'Guest-' . substr(md5($wopi->getToken()), 0, 8) : $wopi->getEditorUid(),
			'OwnerId' => $wopi->getOwnerUid(),
			'UserFriendlyName' => $displayName,
			'IsAnonymousUser' => $isAnonymous,
			'UserCanWrite' => $canWrite,
			// PutRelativeFile (Save As) deferred to Phase 6.
			'UserCanNotWriteRelative' => true,
			'PostMessageOrigin' => $wopi->getServerHost(),
			'LastModifiedTime' => $this->toISO8601($file->getMTime()),
			'SupportsUpdate' => true,
			// WOPI locks live in office_wopi_locks, not in an NC lock provider, and PutFile requires one
			// on non-empty files: clients only send LOCK when this is true, so it must not depend on files_lock.
			'SupportsLocks' => true,
			'SupportsGetLock' => true,
			'SupportsExtendedLockLength' => true,
			// Share-link sessions act on someone else's file through the link: no renaming or resharing,
			// even for a logged-in visitor.
			'SupportsRename' => !$isShareSession,
			'UserCanRename' => !$isShareSession,
			'EnableInsertRemoteImage' => !$isShareSession,
			'EnableShare' => !$isShareSession,
			'HideExportOption' => $hideDownload,
			'DisablePrint' => $hideDownload,
			'DisableExport' => $hideDownload,
			'HideUserList' => '',
			'EnableOwnerTermination' => $canWrite && !$isShareSession,
			'HasContentRange' => true,
			// ServerPrivateInfo is intentionally empty — credentials must never travel via CheckFileInfo.
			'ServerPrivateInfo' => [],
		]);
	}

	/**
	 * WOPI GetFile — returns the binary content of the file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: 'wopi/files/{fileId}/contents')]
	public function getFile(
		int $fileId,
		#[\SensitiveParameter]
		string $access_token,
	): Http\Response {
		try {
			$wopi = $this->wopiMapper->getWopiForToken($access_token);
		} catch (UnknownTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (ExpiredTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($wopi->getFileid() !== $fileId) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$access = $this->resolveAccess($wopi);
		if (!$access instanceof WopiAccess) {
			return $access;
		}
		$file = $access->file;

		if ($file->getSize() === 0) {
			$empty = new Http\Response();
			$empty->addHeader('Content-Type', 'application/octet-stream');
			return $empty;
		}

		$rangeHeader = $this->request->getHeader('Range');
		if ($rangeHeader !== '') {
			return $this->getFileRange($file, $rangeHeader);
		}

		$resource = $file->fopen('rb');
		if ($resource === false) {
			return new JSONResponse(['message' => 'Could not open file for reading'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$response = new StreamResponse($resource);
		$response->addHeader('Content-Type', 'application/octet-stream');
		return $response;
	}

	/**
	 * WOPI PutFile — saves binary content sent by the editor.
	 *
	 * Nextcloud's advisory locking is acquired around the write so other
	 * processes see a consistent file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: 'wopi/files/{fileId}/contents')]
	public function putFile(
		int $fileId,
		#[\SensitiveParameter]
		string $access_token,
	): JSONResponse {
		try {
			$wopi = $this->wopiMapper->getWopiForToken($access_token);
		} catch (UnknownTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (ExpiredTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($wopi->getFileid() !== $fileId) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$access = $this->resolveAccess($wopi);
		if (!$access instanceof WopiAccess) {
			return $access;
		}
		if (!$access->canWrite) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}
		$file = $access->file;

		// Enforce WOPI lock: if a non-expired lock exists the client must supply the matching lock ID.
		$lockId = $this->request->getHeader('X-WOPI-Lock');
		$existingLock = $this->wopiLockMapper->findByFileId($fileId);
		if ($existingLock !== null && !$existingLock->isExpired()) {
			if ($lockId !== $existingLock->getLockId()) {
				return $this->lockConflict($existingLock->getLockId(), 'File is locked');
			}
		}

		// Per WOPI spec §3.3.5.3: a non-empty file must be locked before it can be saved.
		if (($existingLock === null || $existingLock->isExpired()) && $file->getSize() > 0) {
			return $this->lockConflict('', 'A lock is required to save a non-empty file');
		}

		// Editors echo back the LastModifiedTime from CheckFileInfo or the previous PutFile; a mismatch means
		// the file changed outside this editing session. Collabora (X-COOL-WOPI-Timestamp) answers
		// COOLStatusCode 1010 with a "document changed" prompt, as with richdocuments. Euro-Office
		// (X-LOOL-WOPI-Timestamp) has no handling for it and only refreshes its timestamp from a successful
		// PutFile, so a 409 would fail every later save of the session: write anyway, the previous content
		// stays in the file's versions, and log the overwrite.
		$coolTimestamp = $this->request->getHeader('X-COOL-WOPI-Timestamp');
		$loolTimestamp = $this->request->getHeader('X-LOOL-WOPI-Timestamp');
		if ($coolTimestamp !== '' && !$this->isCurrentTimestamp($coolTimestamp, $file)) {
			$this->logger->info('Rejected save of file {fileId}: changed outside the editing session', ['fileId' => $fileId]);
			return new JSONResponse(['COOLStatusCode' => self::COOL_STATUS_DOC_CHANGED], Http::STATUS_CONFLICT);
		}
		if ($coolTimestamp === '' && $loolTimestamp !== '' && !$this->isCurrentTimestamp($loolTimestamp, $file)) {
			$this->logger->warning('Save of file {fileId} overwrites a change made outside the editing session', [
				'fileId' => $fileId,
				'editorTimestamp' => $loolTimestamp,
				'fileTimestamp' => $this->toISO8601($file->getMTime()),
			]);
		}

		try {
			$content = fopen('php://input', 'rb');
			try {
				$freespace = $file->getStorage()->free_space($file->getInternalPath());
				$contentLength = (int)$this->request->getHeader('Content-Length');
				if ($freespace >= 0 && $contentLength > $freespace) {
					return new JSONResponse(['message' => 'Not enough storage'], Http::STATUS_INSUFFICIENT_STORAGE);
				}

				$this->writeWithLock($wopi, $file, static function () use ($file, $content): void {
					$file->putContent($content);
				});
			} finally {
				// putContent() may close the stream internally; guard to avoid a warning.
				if (is_resource($content)) {
					fclose($content);
				}
			}
		} catch (LockedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['message' => 'File locked'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new JSONResponse(['LastModifiedTime' => $this->toISO8601($file->getMTime())]);
		$response->addHeader('X-WOPI-ItemVersion', (string)$file->getMTime());
		return $response;
	}

	/**
	 * WOPI lock operations — all arrive as POST wopi/files/{fileId} with
	 * an X-WOPI-Override header selecting the operation.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: 'wopi/files/{fileId}')]
	public function executeOperation(
		int $fileId,
		#[\SensitiveParameter]
		string $access_token,
	): Http\Response {
		try {
			$wopi = $this->wopiMapper->getWopiForToken($access_token);
		} catch (UnknownTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (ExpiredTokenException $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($wopi->getFileid() !== $fileId) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$access = $this->resolveAccess($wopi);
		if (!$access instanceof WopiAccess) {
			return $access;
		}
		if (!$access->canWrite) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$override = $this->request->getHeader('X-WOPI-Override');
		$lockId = $this->request->getHeader('X-WOPI-Lock');

		return match ($override) {
			'LOCK' => $this->handleLock($fileId, $lockId),
			'UNLOCK' => $this->handleUnlock($fileId, $lockId),
			'REFRESH_LOCK' => $this->handleRefreshLock($fileId, $lockId),
			'GET_LOCK' => $this->handleGetLock($fileId),
			'RENAME_FILE' => $this->handleRenameFile($fileId, $wopi, $access->file),
			default => new JSONResponse(['error' => 'Unsupported WOPI operation'], Http::STATUS_NOT_IMPLEMENTED),
		};
	}

	private function handleLock(int $fileId, string $lockId): Http\Response {
		if ($lockId === '') {
			return new JSONResponse(['error' => 'X-WOPI-Lock is required'], Http::STATUS_BAD_REQUEST);
		}

		$oldLockId = $this->request->getHeader('X-WOPI-OldLock');
		$existing = $this->wopiLockMapper->findByFileId($fileId);

		if ($oldLockId !== '') {
			// UnlockAndRelock: verify the old lock first, then replace with new.
			if ($existing === null || $existing->isExpired() || $existing->getLockId() !== $oldLockId) {
				$current = ($existing !== null && !$existing->isExpired()) ? $existing->getLockId() : '';
				return $this->lockConflict($current, 'Lock mismatch on UnlockAndRelock');
			}
			$this->wopiLockMapper->upsertLock($fileId, $lockId);
			return $this->lockOkResponse();
		}

		if ($existing !== null && !$existing->isExpired()) {
			if ($existing->getLockId() !== $lockId) {
				return $this->lockConflict($existing->getLockId(), 'File is locked by another client');
			}
			// Idempotent re-lock with same ID — refresh the TTL.
			$this->wopiLockMapper->upsertLock($fileId, $lockId);
			return $this->lockOkResponse();
		}

		$this->wopiLockMapper->upsertLock($fileId, $lockId);
		return $this->lockOkResponse();
	}

	private function handleUnlock(int $fileId, string $lockId): Http\Response {
		if ($lockId === '') {
			return new JSONResponse(['error' => 'X-WOPI-Lock is required'], Http::STATUS_BAD_REQUEST);
		}

		$existing = $this->wopiLockMapper->findByFileId($fileId);

		if ($existing === null || $existing->isExpired() || $existing->getLockId() !== $lockId) {
			$current = ($existing !== null && !$existing->isExpired()) ? $existing->getLockId() : '';
			return $this->lockConflict($current, 'Lock mismatch');
		}

		$this->wopiLockMapper->delete($existing);
		return $this->lockOkResponse();
	}

	private function handleRefreshLock(int $fileId, string $lockId): Http\Response {
		if ($lockId === '') {
			return new JSONResponse(['error' => 'X-WOPI-Lock is required'], Http::STATUS_BAD_REQUEST);
		}

		$existing = $this->wopiLockMapper->findByFileId($fileId);

		if ($existing === null || $existing->isExpired() || $existing->getLockId() !== $lockId) {
			$current = ($existing !== null && !$existing->isExpired()) ? $existing->getLockId() : '';
			return $this->lockConflict($current, 'Lock mismatch on RefreshLock');
		}

		$this->wopiLockMapper->upsertLock($fileId, $lockId);
		return $this->lockOkResponse();
	}

	private function handleGetLock(int $fileId): Http\Response {
		$existing = $this->wopiLockMapper->findByFileId($fileId);
		$current = ($existing !== null && !$existing->isExpired()) ? $existing->getLockId() : '';

		$response = new JSONResponse([]);
		$response->addHeader('X-WOPI-Lock', $current);
		return $response;
	}

	private function handleRenameFile(int $fileId, Wopi $wopi, File $file): Http\Response {
		// Renaming acts on the owner's file: not through a share link, logged in or not.
		if ($wopi->isShareSession()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$requestedName = $this->request->getHeader('X-WOPI-RequestedName');
		if ($requestedName === '') {
			return new JSONResponse(['error' => 'X-WOPI-RequestedName is required'], Http::STATUS_BAD_REQUEST);
		}

		// X-WOPI-RequestedName is UTF-7 encoded; decode to UTF-8.
		$newBaseName = (string)mb_convert_encoding($requestedName, 'UTF-8', 'UTF-7');

		// Reject names that contain path separators or null bytes (path traversal guard).
		// basename() only treats '/' as a separator on non-Windows PHP, so a
		// backslash is checked explicitly too - Windows-separator names have no
		// legitimate use in a rename, and NC storage treats '\' as an ordinary
		// character rather than a path component.
		if ($newBaseName === '' || $newBaseName !== basename($newBaseName) || str_contains($newBaseName, "\0") || str_contains($newBaseName, '\\')) {
			$resp = new JSONResponse([], Http::STATUS_BAD_REQUEST);
			$resp->addHeader('X-WOPI-InvalidFileNameError', 'File name contains invalid characters');
			return $resp;
		}

		// Lock check: an active lock must be matched by the client's X-WOPI-Lock.
		$lockId = $this->request->getHeader('X-WOPI-Lock');
		$existing = $this->wopiLockMapper->findByFileId($fileId);
		if ($existing !== null && !$existing->isExpired()) {
			if ($lockId !== $existing->getLockId()) {
				return $this->lockConflict($existing->getLockId(), 'File is locked');
			}
		}

		$extension = pathinfo($file->getName(), PATHINFO_EXTENSION);
		$newName = $extension !== '' ? $newBaseName . '.' . $extension : $newBaseName;

		// Reject if a file with the requested name already exists in the same directory.
		try {
			$file->getParent()->get($newName);
			$response = new JSONResponse([], Http::STATUS_BAD_REQUEST);
			$response->addHeader('X-WOPI-InvalidFileNameError', 'A file with that name already exists');
			return $response;
		} catch (NotFoundException) {
			// Target name is free — proceed with rename.
		}

		$newPath = $file->getParent()->getPath() . '/' . $newName;

		try {
			try {
				$this->lockManager->runInScope(
					new LockContext($file, \OCP\Files\Lock\ILock::TYPE_APP, 'office'),
					static function () use (&$file, $newPath): void {
						$file = $file->move($newPath);
					},
				);
			} catch (NoLockProviderException|PreConditionNotMetException) {
				$file = $file->move($newPath);
			} catch (OwnerLockedException) {
				return $this->lockConflict('', 'File is locked by another user');
			}
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['Name' => $file->getName()]);
	}

	private function lockOkResponse(): JSONResponse {
		return new JSONResponse([]);
	}

	private function lockConflict(string $currentLockId, string $reason): JSONResponse {
		$response = new JSONResponse([], Http::STATUS_CONFLICT);
		$response->addHeader('X-WOPI-Lock', $currentLockId);
		$response->addHeader('X-WOPI-LockFailureReason', $reason);
		return $response;
	}

	/**
	 * The token's file and current rights, or the response to send when the file
	 * or the share it was opened through is gone.
	 */
	private function resolveAccess(Wopi $wopi): WopiAccess|JSONResponse {
		try {
			return $this->accessResolver->resolve($wopi);
		} catch (NotFoundException $e) {
			$this->logger->info($e->getMessage(), ['fileId' => $wopi->getFileid()]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	private function getFileRange(File $file, string $rangeHeader): Http\Response {
		$size = $file->getSize();
		if (preg_match('/bytes=(\d+)-(\d+)?/', $rangeHeader, $m)) {
			$start = (int)$m[1];
			$end = isset($m[2]) ? (int)$m[2] : $size - 1;
			// Clamp end to actual file size to produce a correct Content-Range header (RFC 7233).
			$end = min($end, $size - 1);

			// RFC 7233 §4.4: return 416 if range is unsatisfiable.
			if ($start >= $size || $start > $end) {
				$response = new Http\Response();
				$response->setStatus(416);
				$response->addHeader('Content-Range', "bytes */{$size}");
				return $response;
			}

			$length = (int)($end - $start + 1);

			$fp = $file->fopen('rb');
			if ($fp === false) {
				$r = new Http\Response();
				$r->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
				return $r;
			}
			try {
				$rangeStream = fopen('php://temp', 'w+b');
				if ($rangeStream === false) {
					$r = new Http\Response();
					$r->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
					return $r;
				}
				try {
					stream_copy_to_stream($fp, $rangeStream, $length, $start);
					fseek($rangeStream, 0);

					$response = new StreamResponse($rangeStream);
					$response->setStatus(Http::STATUS_PARTIAL_CONTENT);
					$response->addHeader('Content-Type', 'application/octet-stream');
					$response->addHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
					$response->addHeader('Content-Length', (string)$length);
					$response->addHeader('Accept-Ranges', 'bytes');
					return $response;
				} catch (\Throwable $e) {
					fclose($rangeStream);
					throw $e;
				}
			} finally {
				fclose($fp);
			}
		}

		$resource = $file->fopen('rb');
		if ($resource === false) {
			$r = new Http\Response();
			$r->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
			return $r;
		}
		$response = new StreamResponse($resource);
		$response->addHeader('Content-Type', 'application/octet-stream');
		return $response;
	}

	/**
	 * Write file content while acquiring an advisory ILockManager lock if available.
	 * Falls back to writing without a lock when no provider is registered.
	 */
	private function writeWithLock(Wopi $wopi, File $file, callable $write): void {
		try {
			$this->lockManager->runInScope(
				new LockContext($file, \OCP\Files\Lock\ILock::TYPE_APP, 'office'),
				$write,
			);
		} catch (NoLockProviderException|PreConditionNotMetException) {
			$write();
		} catch (OwnerLockedException $e) {
			throw new LockedException($file->getPath(), $e);
		}
	}

	/**
	 * Compare at second resolution rather than as strings, so the check doesn't depend on the editor
	 * echoing our exact ISO 8601 format (richdocuments emits microseconds, this app milliseconds).
	 */
	private function isCurrentTimestamp(string $editorTimestamp, File $file): bool {
		try {
			return (new \DateTimeImmutable($editorTimestamp))->getTimestamp() === $file->getMTime();
		} catch (\Exception) {
			return false;
		}
	}

	private function toISO8601(int $timestamp): string {
		return (new \DateTime('@' . $timestamp))->format('Y-m-d\TH:i:s.000\Z');
	}
}
