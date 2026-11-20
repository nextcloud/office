<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Service;

use OCA\Office\Db\Wopi;
use OCA\Office\Service\WopiAccessResolver;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WopiAccessResolverTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private IShareManager&MockObject $shareManager;
	private WopiAccessResolver $resolver;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->resolver = new WopiAccessResolver($this->rootFolder, $this->shareManager);
	}

	private function file(int $id = 82): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);

		return $file;
	}

	private function token(?string $shareToken, ?string $editorUid, bool $canWrite = true, bool $hideDownload = false): Wopi {
		$wopi = new Wopi();
		$wopi->setFileid(82);
		$wopi->setOwnerUid('alice');
		$wopi->setEditorUid($editorUid);
		$wopi->setShareToken($shareToken);
		$wopi->setCanwrite($canWrite);
		$wopi->setHideDownload($hideDownload);

		return $wopi;
	}

	private function shareReturns(int $permissions, Node $node, bool $hideDownload = false): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getNode')->willReturn($node);
		$share->method('getHideDownload')->willReturn($hideDownload);
		$this->shareManager->method('getShareByToken')->with('shareTok')->willReturn($share);
	}

	public function testUserTokenResolvesThroughTheEditorsFolderWithTheTokensRights(): void {
		$file = $this->file();
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(82)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('bob')->willReturn($folder);
		$this->shareManager->expects($this->never())->method('getShareByToken');

		$access = $this->resolver->resolve($this->token(null, 'bob', canWrite: true, hideDownload: true));

		$this->assertSame($file, $access->file);
		$this->assertTrue($access->canWrite);
		$this->assertTrue($access->hideDownload);
	}

	public function testTokenWithNeitherUserNorShareIsRejected(): void {
		$this->expectException(NotFoundException::class);
		$this->resolver->resolve($this->token(null, null));
	}

	public function testShareSessionNeverUsesTheVisitorsOwnFolder(): void {
		$this->shareReturns(Constants::PERMISSION_READ, $this->file());
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->resolver->resolve($this->token('shareTok', 'bob'));
	}

	public function testDeletedOrExpiredShareEndsAccess(): void {
		$this->shareManager->method('getShareByToken')->willThrowException(new ShareNotFound());

		$this->expectException(NotFoundException::class);
		$this->resolver->resolve($this->token('shareTok', null));
	}

	public function testShareThatIsNoLongerReadableEndsAccess(): void {
		$this->shareReturns(0, $this->file());

		$this->expectException(NotFoundException::class);
		$this->resolver->resolve($this->token('shareTok', null));
	}

	public function testShareNarrowedToReadOnlyRevokesWrite(): void {
		$this->shareReturns(Constants::PERMISSION_READ, $this->file());

		$this->assertFalse($this->resolver->resolve($this->token('shareTok', null, canWrite: true))->canWrite);
	}

	public function testWritableShareKeepsAReadOnlyTokenReadOnly(): void {
		$this->shareReturns(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, $this->file());

		$this->assertFalse($this->resolver->resolve($this->token('shareTok', null, canWrite: false))->canWrite);
	}

	public function testWritableShareAllowsWrite(): void {
		$this->shareReturns(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, $this->file());

		$this->assertTrue($this->resolver->resolve($this->token('shareTok', null, canWrite: true))->canWrite);
	}

	public function testDownloadRestrictionAddedToTheShareApplies(): void {
		$this->shareReturns(Constants::PERMISSION_READ, $this->file(), hideDownload: true);

		$this->assertTrue($this->resolver->resolve($this->token('shareTok', null, hideDownload: false))->hideDownload);
	}

	public function testFolderShareResolvesTheFileInsideTheFolder(): void {
		$file = $this->file();
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->with(82)->willReturn($file);
		$this->shareReturns(Constants::PERMISSION_READ, $folder);

		$this->assertSame($file, $this->resolver->resolve($this->token('shareTok', null))->file);
	}

	public function testFolderShareCannotReachAFileOutsideIt(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->with(82)->willReturn(null);
		$this->shareReturns(Constants::PERMISSION_READ, $folder);

		$this->expectException(NotFoundException::class);
		$this->resolver->resolve($this->token('shareTok', null));
	}

	public function testFileShareOfAnotherFileIsRejected(): void {
		$this->shareReturns(Constants::PERMISSION_READ, $this->file(99));

		$this->expectException(NotFoundException::class);
		$this->resolver->resolve($this->token('shareTok', null));
	}
}
