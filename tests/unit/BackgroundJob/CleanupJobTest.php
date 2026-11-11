<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\BackgroundJob;

use OCA\Office\BackgroundJob\CleanupJob;
use OCA\Office\Db\WopiLockMapper;
use OCA\Office\Db\WopiMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CleanupJobTest extends TestCase {
	private WopiMapper&MockObject $wopiMapper;
	private WopiLockMapper&MockObject $wopiLockMapper;

	protected function setUp(): void {
		$this->wopiMapper = $this->createMock(WopiMapper::class);
		$this->wopiLockMapper = $this->createMock(WopiLockMapper::class);
		$this->wopiLockMapper->method('getExpiredLockIds')->willReturn([]);
	}

	private function runJob(): void {
		$job = new CleanupJob($this->createMock(ITimeFactory::class), $this->wopiMapper, $this->wopiLockMapper);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testDeletesExpiredTokensUntilNoneAreLeft(): void {
		$this->wopiMapper->method('getExpiredTokenIds')
			->willReturnOnConsecutiveCalls(range(1, 500), range(501, 1000), [1001], []);
		$this->wopiMapper->expects($this->exactly(3))->method('deleteByIds');

		$this->runJob();
	}

	public function testOneRunIsBounded(): void {
		$this->wopiMapper->method('getExpiredTokenIds')->willReturn(range(1, 500));
		$this->wopiMapper->expects($this->exactly(100))->method('deleteByIds');

		$this->runJob();
	}

	public function testDeletesExpiredLocksUntilNoneAreLeft(): void {
		$this->wopiMapper->method('getExpiredTokenIds')->willReturn([]);
		$lockMapper = $this->createMock(WopiLockMapper::class);
		$lockMapper->method('getExpiredLockIds')->willReturnOnConsecutiveCalls([7, 8], [9], []);
		$lockMapper->expects($this->exactly(2))->method('deleteByIds');
		$this->wopiLockMapper = $lockMapper;

		$this->runJob();
	}
}
