<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Controller;

use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiMapper;
use OCA\Office\Exception\ExpiredTokenException;
use OCA\Office\Exception\UnknownTokenException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
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
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private WopiMapper $wopiMapper,
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
			$file = $this->getFileForToken($wopi);
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

		$user = $this->userManager->get($wopi->getEditorUid() ?? '');
		$displayName = $wopi->isGuest()
			? ($wopi->getGuestDisplayname() ?? 'Guest')
			: ($user?->getDisplayName() ?? $wopi->getEditorUid() ?? '');

		$canWrite = (bool)$wopi->getCanwrite();

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

		return new JSONResponse([
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => (string)$file->getMTime(),
			'UserId' => $wopi->isGuest() ? 'Guest-' . substr(md5($wopi->getToken()), 0, 8) : $wopi->getEditorUid(),
			'OwnerId' => $wopi->getOwnerUid(),
			'UserFriendlyName' => $displayName,
			'UserCanWrite' => $canWrite,
			'UserCanNotWriteRelative' => $wopi->isGuest(),
			'PostMessageOrigin' => $wopi->getServerHost(),
			'LastModifiedTime' => $this->toISO8601($file->getMTime()),
			'SupportsRename' => !$wopi->isGuest(),
			'UserCanRename' => !$wopi->isGuest(),
			'EnableInsertRemoteImage' => !$wopi->isGuest(),
			'EnableShare' => !$wopi->isGuest(),
			'HideUserList' => '',
			'EnableOwnerTermination' => $canWrite && !$wopi->isGuest(),
			'HasContentRange' => true,
			'SupportsLocks' => $this->lockManager->isLockProviderAvailable(),
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

		try {
			$file = $this->getFileForToken($wopi);
		} catch (NotFoundException|NotPermittedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		if ($file->getSize() === 0) {
			return new Http\Response();
		}

		$rangeHeader = $this->request->getHeader('Range');
		if ($rangeHeader !== '') {
			return $this->getFileRange($file, $rangeHeader);
		}

		return new StreamResponse($file->fopen('rb'));
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

		if (!$wopi->getCanwrite()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			$file = $this->getFileForToken($wopi);
		} catch (NotFoundException|NotPermittedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$content = fopen('php://input', 'rb');

			$freespace = $file->getStorage()->free_space($file->getInternalPath());
			$contentLength = (int)$this->request->getHeader('Content-Length');
			if ($freespace >= 0 && $contentLength > $freespace) {
				return new JSONResponse(['message' => 'Not enough storage'], Http::STATUS_INSUFFICIENT_STORAGE);
			}

			$this->writeWithLock($wopi, $file, fn () => $file->putContent($content));
		} catch (LockedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['message' => 'File locked'], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['LastModifiedTime' => $this->toISO8601($file->getMTime())]);
	}

	private function getFileForToken(Wopi $wopi): File {
		$uid = $wopi->getUserForFileAccess();
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$nodes = $userFolder->getById($wopi->getFileid());

		if (empty($nodes)) {
			throw new NotFoundException('File not found for WOPI token');
		}

		// Prefer nodes with write permission when multiple exist (e.g. same file mounted in several places)
		usort($nodes, fn ($a, $b) => ($b->getPermissions() & \OCP\Constants::PERMISSION_UPDATE) <=> ($a->getPermissions() & \OCP\Constants::PERMISSION_UPDATE));

		$node = array_shift($nodes);
		if (!$node instanceof File) {
			throw new NotFoundException('WOPI token points to a directory, not a file');
		}

		return $node;
	}

	private function getFileRange(File $file, string $rangeHeader): Http\Response {
		$size = $file->getSize();
		if (preg_match('/bytes=(\d+)-(\d+)?/', $rangeHeader, $m)) {
			$start = (int)$m[1];
			$end = isset($m[2]) ? (int)$m[2] : $size - 1;
			// Clamp end to actual file size to produce a correct Content-Range header (RFC 7233).
			$end = min($end, $size - 1);
			$length = $end - $start + 1;

			$fp = $file->fopen('rb');
			$rangeStream = fopen('php://temp', 'w+b');
			stream_copy_to_stream($fp, $rangeStream, $length, $start);
			fclose($fp);
			fseek($rangeStream, 0);

			$response = new StreamResponse($rangeStream);
			$response->setStatus(Http::STATUS_PARTIAL_CONTENT);
			$response->addHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
			$response->addHeader('Content-Length', (string)$length);
			$response->addHeader('Accept-Ranges', 'bytes');
			return $response;
		}

		return new StreamResponse($file->fopen('rb'));
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

	private function toISO8601(int $timestamp): string {
		return (new \DateTime('@' . $timestamp))->format('Y-m-d\TH:i:s.000\Z');
	}
}
