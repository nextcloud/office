<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Scope note: the plan for this file also asked for "token randomness/length"
 * and "expiry value sanity" tests. Those don't belong here - TokenManager never
 * touches token generation or expiry at all. Both are entirely
 * WopiMapper::generateFileToken()/generateGuestToken()'s responsibility
 * (ISecureRandom::generate() and TOKEN_TTL live there, not in this class).
 * With WopiMapper mocked, as the plan itself directs, there is nothing of
 * theirs left to exercise through TokenManager. See wopi-test-suite-RESULTS.md
 * Task 5 section.
 */

namespace OCA\Office\Tests\Unit;

use OCA\Office\Db\Wopi;
use OCA\Office\Db\WopiMapper;
use OCA\Office\TokenManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\BeforeNodeReadEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TokenManagerTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private WopiMapper&MockObject $wopiMapper;
	private IURLGenerator&MockObject $urlGenerator;
	private IEventDispatcher&MockObject $eventDispatcher;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->wopiMapper = $this->createMock(WopiMapper::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
	}

	private function manager(?string $userId = 'admin'): TokenManager {
		return new TokenManager(
			$this->rootFolder,
			$this->wopiMapper,
			$this->urlGenerator,
			$this->eventDispatcher,
			$userId,
		);
	}

	/** @param array{readable?: bool, updateable?: bool, mtime?: int, owner?: ?IUser} $overrides */
	private function fileMock(array $overrides = []): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($overrides['readable'] ?? true);
		$file->method('isUpdateable')->willReturn($overrides['updateable'] ?? true);
		$file->method('getMTime')->willReturn($overrides['mtime'] ?? 1700000000);
		$file->method('getOwner')->willReturn($overrides['owner'] ?? null);

		return $file;
	}

	private function userFolderReturning(?File $file, string $expectedUid = 'admin'): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($file);
		$this->rootFolder->method('getUserFolder')->with($expectedUid)->willReturn($folder);
	}

	// --- generateToken() (authenticated user) ---

	public function testGenerateTokenCapturesServerHostFromUrlGenerator(): void {
		$this->userFolderReturning($this->fileMock());
		$this->urlGenerator->method('getAbsoluteURL')->with('/')->willReturn('https://nc.example.com/');

		$this->wopiMapper->expects($this->once())
			->method('generateFileToken')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 'https://nc.example.com/')
			->willReturn(new Wopi());

		$this->manager()->generateToken(82);
	}

	public function testGenerateTokenPassesFileIdOwnerEditorVersionAndCanWrite(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner-uid');
		$this->userFolderReturning($this->fileMock(['owner' => $owner, 'updateable' => true, 'mtime' => 1700000000]));

		$this->wopiMapper->expects($this->once())
			->method('generateFileToken')
			->with(82, 'owner-uid', 'admin', '1700000000', true, $this->anything())
			->willReturn(new Wopi());

		$this->manager('admin')->generateToken(82);
	}

	public function testGenerateTokenCanWriteFalseWhenFileNotUpdateable(): void {
		$this->userFolderReturning($this->fileMock(['updateable' => false]));

		$this->wopiMapper->expects($this->once())
			->method('generateFileToken')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), false, $this->anything())
			->willReturn(new Wopi());

		$this->manager()->generateToken(82);
	}

	public function testGenerateTokenFallsBackToUserIdWhenFileHasNoOwner(): void {
		$this->userFolderReturning($this->fileMock(['owner' => null]));

		$this->wopiMapper->expects($this->once())
			->method('generateFileToken')
			->with($this->anything(), 'admin', $this->anything(), $this->anything(), $this->anything(), $this->anything())
			->willReturn(new Wopi());

		$this->manager('admin')->generateToken(82);
	}

	public function testGenerateTokenDispatchesBeforeNodeReadEvent(): void {
		$this->userFolderReturning($this->fileMock());
		$this->wopiMapper->method('generateFileToken')->willReturn(new Wopi());

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->isInstanceOf(BeforeNodeReadEvent::class));

		$this->manager()->generateToken(82);
	}

	public function testGenerateTokenThrowsWhenFileNotReadable(): void {
		$this->userFolderReturning($this->fileMock(['readable' => false]));

		$this->expectException(NotPermittedException::class);
		$this->manager()->generateToken(82);
	}

	public function testGenerateTokenThrowsWhenFileNotFound(): void {
		$this->userFolderReturning(null);

		$this->expectException(NotPermittedException::class);
		$this->manager()->generateToken(82);
	}

	public function testGenerateTokenReturnsWopiEntityFromMapper(): void {
		$this->userFolderReturning($this->fileMock());
		$expected = new Wopi();
		$this->wopiMapper->method('generateFileToken')->willReturn($expected);

		$result = $this->manager()->generateToken(82);

		$this->assertSame($expected, $result);
	}

	// --- generateGuestToken() (share-link guest) ---

	public function testGenerateGuestTokenPropagatesGuestNameCanWriteAndHideDownload(): void {
		$this->userFolderReturning($this->fileMock(), 'owner-uid');

		$this->wopiMapper->expects($this->once())
			->method('generateGuestToken')
			->with(82, 'owner-uid', 'Guest Name', $this->anything(), true, true, $this->anything())
			->willReturn(new Wopi());

		$this->manager()->generateGuestToken(82, 'owner-uid', 'Guest Name', true, true);
	}

	public function testGenerateGuestTokenResolvesFileViaOwnerFolderNotCurrentUser(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturn($this->fileMock());
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('owner-uid')->willReturn($folder);
		$this->wopiMapper->method('generateGuestToken')->willReturn(new Wopi());

		// TokenManager's own userId ('current-user') must play no part in a guest
		// token - the file is resolved via the file OWNER's folder, since guests
		// have no folder of their own.
		$this->manager('current-user')->generateGuestToken(82, 'owner-uid', 'Guest', true, false);
	}

	public function testGenerateGuestTokenCapturesServerHostFromUrlGenerator(): void {
		$this->userFolderReturning($this->fileMock(), 'owner-uid');
		$this->urlGenerator->method('getAbsoluteURL')->with('/')->willReturn('https://nc.example.com/');

		$this->wopiMapper->expects($this->once())
			->method('generateGuestToken')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 'https://nc.example.com/')
			->willReturn(new Wopi());

		$this->manager()->generateGuestToken(82, 'owner-uid', 'Guest', true, false);
	}

	public function testGenerateGuestTokenDispatchesBeforeNodeReadEvent(): void {
		$this->userFolderReturning($this->fileMock(), 'owner-uid');
		$this->wopiMapper->method('generateGuestToken')->willReturn(new Wopi());

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->isInstanceOf(BeforeNodeReadEvent::class));

		$this->manager()->generateGuestToken(82, 'owner-uid', 'Guest', true, false);
	}

	public function testGenerateGuestTokenThrowsWhenFileNotReadable(): void {
		$this->userFolderReturning($this->fileMock(['readable' => false]), 'owner-uid');

		$this->expectException(NotPermittedException::class);
		$this->manager()->generateGuestToken(82, 'owner-uid', 'Guest', true, false);
	}

	public function testGenerateGuestTokenThrowsWhenFileNotFound(): void {
		$this->userFolderReturning(null, 'owner-uid');

		$this->expectException(NotPermittedException::class);
		$this->manager()->generateGuestToken(82, 'owner-uid', 'Guest', true, false);
	}

	public function testGenerateGuestTokenReturnsWopiEntityFromMapper(): void {
		$this->userFolderReturning($this->fileMock(), 'owner-uid');
		$expected = new Wopi();
		$this->wopiMapper->method('generateGuestToken')->willReturn($expected);

		$result = $this->manager()->generateGuestToken(82, 'owner-uid', 'Guest', true, false);

		$this->assertSame($expected, $result);
	}
}
