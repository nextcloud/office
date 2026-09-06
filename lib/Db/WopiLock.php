<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method void setFileid(int $fileid)
 * @method int getFileid()
 * @method void setLockId(string $lockId)
 * @method string getLockId()
 * @method void setExpiry(int $expiry)
 * @method int getExpiry()
 */
class WopiLock extends Entity {
	/** @var int */
	protected $fileid = 0;

	/** @var string */
	protected $lockId = '';

	/** @var int */
	protected $expiry = 0;

	public function __construct() {
		$this->addType('fileid', Types::INTEGER);
		$this->addType('lockId', Types::STRING);
		$this->addType('expiry', Types::INTEGER);
	}

	public function isExpired(): bool {
		return $this->expiry < time();
	}
}
