<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\BackgroundJob;

use OCA\Office\Db\WopiMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CleanupJob extends TimedJob {
	private const BATCH_SIZE = 500;

	public function __construct(ITimeFactory $time, private WopiMapper $wopiMapper) {
		parent::__construct($time);
		$this->setInterval(3600); // run hourly
	}

	protected function run(mixed $argument): void {
		$ids = $this->wopiMapper->getExpiredTokenIds(self::BATCH_SIZE);
		if (empty($ids)) {
			return;
		}
		$this->wopiMapper->deleteByIds($ids);
	}
}
