<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office;

use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiMapper;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class TokenManager {
	public function __construct(
		private IRootFolder $rootFolder,
		private WopiMapper $wopiMapper,
		private IURLGenerator $urlGenerator,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
	}

	/**
	 * Generate a WOPI token for an authenticated user opening a file.
	 *
	 * @param int $fileId NC file ID
	 * @throws NotPermittedException if the file is unreadable or the user has no access
	 * @throws \OCP\Files\NotFoundException if the file does not exist
	 */
	public function generateToken(int $fileId): Wopi {
		$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);

		$file = $userFolder->getFirstNodeById($fileId);

		if (!$file instanceof File || !$file->isReadable()) {
			throw new NotPermittedException();
		}

		$canWrite = $file->isUpdateable();

		$owner = $file->getOwner();
		$ownerUid = $owner !== null ? $owner->getUID() : (string)$this->userId;

		// Fire the read event so audit logging picks it up.
		$this->eventDispatcher->dispatchTyped(new BeforeNodeReadEvent($file));

		$serverHost = $this->urlGenerator->getAbsoluteURL('/');
		$version = (string)$file->getMtime();

		return $this->wopiMapper->generateFileToken(
			fileId: $fileId,
			ownerUid: $ownerUid,
			editorUid: (string)$this->userId,
			version: $version,
			canWrite: $canWrite,
			serverHost: $serverHost,
		);
	}

	/**
	 * Generate a WOPI token for a guest opening a file via a share link.
	 *
	 * @param int    $fileId          NC file ID (must be reachable via $ownerUid)
	 * @param string $ownerUid        File owner whose storage is used for I/O
	 * @param string $guestName       Display name shown in the editor
	 * @param bool   $canWrite        Whether the share allows editing
	 */
	public function generateGuestToken(
		int $fileId,
		string $ownerUid,
		string $guestName,
		bool $canWrite,
	): Wopi {
		$ownerFolder = $this->rootFolder->getUserFolder($ownerUid);
		$file = $ownerFolder->getFirstNodeById($fileId);

		if (!$file instanceof File || !$file->isReadable()) {
			throw new NotPermittedException();
		}

		$this->eventDispatcher->dispatchTyped(new BeforeNodeReadEvent($file));

		$serverHost = $this->urlGenerator->getAbsoluteURL('/');
		$version = (string)$file->getMtime();

		return $this->wopiMapper->generateGuestToken(
			fileId: $fileId,
			ownerUid: $ownerUid,
			guestDisplayname: $guestName,
			version: $version,
			canWrite: $canWrite,
			serverHost: $serverHost,
		);
	}
}
