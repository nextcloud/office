<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Token expiry behavior and CleanupJob, invoked directly (not through cron/
 * IJobList::start(), which would depend on this job's last-run timestamp in
 * the live job table - reflection on the protected run() method is the
 * direct, deterministic way to exercise it, per the plan).
 */

namespace OCA\Office\Tests\Integration;

use OCA\Office\BackgroundJob\CleanupJob;
use OCA\Office\Db\WopiLockMapper;
use OCA\Office\Db\WopiMapper;
use OCA\Office\Exception\UnknownTokenException;
use OCP\BackgroundJob\IJobList;
use OCP\Server;

class CleanupJobTest extends IntegrationTestCase {
	private function runCleanupJob(): void {
		$job = Server::get(CleanupJob::class);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testCheckFileInfoReturns401ForExpiredToken(): void {
		$file = $this->createTestFile('expired-token.docx');
		$wopiMapper = Server::get(WopiMapper::class);
		$wopi = $this->generateToken($file);
		$wopi->setExpiry(time() - 60);
		$wopiMapper->update($wopi);

		$response = static::$http->get($this->wopiFilesUrl($file->getId(), $wopi->getToken()));

		$this->assertSame(401, $response->getStatusCode());
	}

	public function testCleanupJobPurgesExpiredToken(): void {
		$file = $this->createTestFile('cleanup-token.docx');
		$wopiMapper = Server::get(WopiMapper::class);
		$wopi = $this->generateToken($file);
		$wopi->setExpiry(time() - 3700); // past getExpiredTokenIds()'s 60s grace window too
		$wopiMapper->update($wopi);

		$this->runCleanupJob();

		$this->expectException(UnknownTokenException::class);
		$wopiMapper->getWopiForToken($wopi->getToken());
	}

	public function testCleanupJobPurgesExpiredLock(): void {
		$file = $this->createTestFile('cleanup-lock.docx');
		$wopiLockMapper = Server::get(WopiLockMapper::class);
		$lock = $wopiLockMapper->upsertLock($file->getId(), 'expiring-lock');
		$lock->setExpiry(time() - 60);
		$wopiLockMapper->update($lock);

		$this->runCleanupJob();

		$this->assertNull($wopiLockMapper->findByFileId($file->getId()));
	}

	public function testCleanupJobLeavesUnexpiredTokensAndLocksAlone(): void {
		$file = $this->createTestFile('cleanup-keep.docx');
		$wopiMapper = Server::get(WopiMapper::class);
		$wopiLockMapper = Server::get(WopiLockMapper::class);
		$wopi = $this->generateToken($file);
		$wopiLockMapper->upsertLock($file->getId(), 'still-active');

		$this->runCleanupJob();

		// Neither throws nor returns null - both survive.
		$survivingWopi = $wopiMapper->getWopiForToken($wopi->getToken());
		$this->assertSame($file->getId(), $survivingWopi->getFileid());
		$this->assertNotNull($wopiLockMapper->findByFileId($file->getId()));
	}

	public function testCleanupJobIsRegisteredInJobList(): void {
		$jobList = Server::get(IJobList::class);

		$this->assertTrue($jobList->has(CleanupJob::class, null));
	}
}
