<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Db;

use OCA\Office\Exception\ExpiredTokenException;
use OCA\Office\Exception\UnknownTokenException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/** @template-extends QBMapper<Wopi> */
class WopiMapper extends QBMapper {
	private const TOKEN_TTL = 36000; // 10 hours

	public function __construct(
		IDBConnection $db,
		private ISecureRandom $random,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($db, 'office_wopi', Wopi::class);
	}

	/**
	 * Generate and persist a WOPI token for an authenticated user.
	 */
	public function generateFileToken(
		int $fileId,
		string $ownerUid,
		string $editorUid,
		string $version,
		bool $canWrite,
		string $serverHost,
	): Wopi {
		$token = $this->random->generate(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS);

		/** @var Wopi $wopi */
		$wopi = $this->insert(Wopi::fromParams([
			'fileid' => $fileId,
			'ownerUid' => $ownerUid,
			'editorUid' => $editorUid,
			'version' => $version,
			'canwrite' => $canWrite,
			'serverHost' => $serverHost,
			'token' => $token,
			'expiry' => $this->newExpiry(),
		]));

		return $wopi;
	}

	/**
	 * Generate and persist a WOPI token for a guest (share link) editor.
	 */
	public function generateGuestToken(
		int $fileId,
		string $ownerUid,
		string $guestDisplayname,
		string $version,
		bool $canWrite,
		string $serverHost,
	): Wopi {
		$token = $this->random->generate(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS);

		/** @var Wopi $wopi */
		$wopi = $this->insert(Wopi::fromParams([
			'fileid' => $fileId,
			'ownerUid' => $ownerUid,
			'editorUid' => null,
			'guestDisplayname' => $guestDisplayname,
			'version' => $version,
			'canwrite' => $canWrite,
			'serverHost' => $serverHost,
			'token' => $token,
			'expiry' => $this->newExpiry(),
		]));

		return $wopi;
	}

	/**
	 * Look up and validate a WOPI token.
	 *
	 * @throws UnknownTokenException
	 * @throws ExpiredTokenException
	 */
	public function getWopiForToken(
		#[\SensitiveParameter]
		string $token,
	): Wopi {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('office_wopi')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new UnknownTokenException('Could not find token.');
		}

		// Redact the token value before logging to avoid credential exposure in log files.
		$safeRow = $row;
		$safeRow['token'] = '***';
		$this->logger->debug('Loaded WOPI token record: {row}.', ['row' => $safeRow]);

		/** @var Wopi $wopi */
		$wopi = Wopi::fromRow($row);

		if ($wopi->isExpired()) {
			throw new ExpiredTokenException('Provided token is expired.');
		}

		return $wopi;
	}

	/**
	 * Return IDs of tokens that expired more than 60 seconds ago, for cleanup jobs.
	 *
	 * @return int[]
	 */
	public function getExpiredTokenIds(?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('office_wopi')
			->where($qb->expr()->lt('expiry', $qb->createNamedParameter(time() - 60, IQueryBuilder::PARAM_INT)))
			->setFirstResult($offset)
			->setMaxResults($limit);

		return array_column($qb->executeQuery()->fetchAll(), 'id');
	}

	private function newExpiry(): int {
		return $this->timeFactory->getTime() + self::TOKEN_TTL;
	}
}
