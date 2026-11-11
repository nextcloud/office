<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

use OCA\Office\Db\Wopi;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;

/**
 * Resolve a WOPI token to its file and current rights, on every request.
 *
 * User tokens reach the file through the editor's own folder. Share-link tokens go
 * through the live share, so deleting it, letting it expire or narrowing it takes
 * effect on the next request instead of when the token expires.
 */
final class WopiAccessResolver {
	public function __construct(
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
	) {
	}

	/**
	 * @throws NotFoundException if the file, or the share it was opened through, is gone
	 */
	public function resolve(Wopi $wopi): WopiAccess {
		if ($wopi->isShareSession()) {
			return $this->resolveThroughShare($wopi);
		}

		$editorUid = $wopi->getEditorUid();
		if ($editorUid === null) {
			// Share-link token from before tokens were bound to their share.
			throw new NotFoundException('WOPI token has neither a user nor a share');
		}

		$nodes = $this->rootFolder->getUserFolder($editorUid)->getById($wopi->getFileid());

		// Prefer nodes with write permission when multiple exist (e.g. same file mounted in several places)
		usort($nodes, static function (Node $a, Node $b): int {
			return ($b->getPermissions() & Constants::PERMISSION_UPDATE) <=> ($a->getPermissions() & Constants::PERMISSION_UPDATE);
		});

		$file = $nodes[0] ?? null;
		if (!$file instanceof File) {
			throw new NotFoundException('File not found for WOPI token');
		}

		return new WopiAccess($file, $wopi->getCanwrite(), $wopi->getHideDownload());
	}

	/**
	 * @throws NotFoundException
	 */
	private function resolveThroughShare(Wopi $wopi): WopiAccess {
		try {
			$share = $this->shareManager->getShareByToken((string)$wopi->getShareToken());
		} catch (ShareNotFound) {
			throw new NotFoundException('The share of this WOPI token no longer exists');
		}

		$permissions = $share->getPermissions();
		if (($permissions & Constants::PERMISSION_READ) === 0) {
			throw new NotFoundException('The share of this WOPI token is no longer readable');
		}

		// The share's node carries the owner's permissions; the share's own permissions decide.
		$node = $share->getNode();
		if ($node instanceof Folder) {
			// Scoped to the shared folder: a token can't reach files outside it.
			$node = $node->getFirstNodeById($wopi->getFileid());
		}
		if (!$node instanceof File || $node->getId() !== $wopi->getFileid()) {
			throw new NotFoundException('The share of this WOPI token no longer contains its file');
		}

		return new WopiAccess(
			$node,
			$wopi->getCanwrite() && ($permissions & Constants::PERMISSION_UPDATE) !== 0,
			$wopi->getHideDownload() || $share->getHideDownload(),
		);
	}
}
