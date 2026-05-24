<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<WopiLock> */
class WopiLockMapper extends QBMapper {
	// WOPI spec: locks are valid for 30 minutes; editor must refresh before expiry.
	public const LOCK_TTL = 1800;

	public function __construct(
		IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($db, 'office_wopi_locks', WopiLock::class);
	}

	/**
	 * Return the current non-expired lock for a file, or null if none exists.
	 */
	public function findByFileId(int $fileId): ?WopiLock {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('office_wopi_locks')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		try {
			/** @var WopiLock $lock */
			$lock = $this->findEntity($qb);
			return $lock;
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Create or refresh a lock for a file.
	 * If a lock already exists for the file it is updated in-place.
	 */
	public function upsertLock(int $fileId, string $lockId): WopiLock {
		$expiry = $this->timeFactory->getTime() + self::LOCK_TTL;
		$existing = $this->findByFileId($fileId);

		if ($existing !== null) {
			$existing->setLockId($lockId);
			$existing->setExpiry($expiry);
			/** @var WopiLock $updated */
			$updated = $this->update($existing);
			return $updated;
		}

		$lock = new WopiLock();
		$lock->setFileid($fileId);
		$lock->setLockId($lockId);
		$lock->setExpiry($expiry);
		/** @var WopiLock $inserted */
		$inserted = $this->insert($lock);
		return $inserted;
	}

	/**
	 * Return IDs of expired lock rows for the cleanup job.
	 *
	 * @return int[]
	 */
	public function getExpiredLockIds(int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('office_wopi_locks')
			->where($qb->expr()->lt('expiry', $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);

		return array_column($qb->executeQuery()->fetchAll(), 'id');
	}

	/**
	 * Delete lock rows by their primary-key IDs.
	 *
	 * @param int[] $ids
	 */
	public function deleteByIds(array $ids): void {
		if (empty($ids)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('office_wopi_locks')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}
}
