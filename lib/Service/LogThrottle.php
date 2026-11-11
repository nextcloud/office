<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

use OCP\ICacheFactory;

/**
 * Lets a warning through at most once per period on this node. Every log line Nextcloud
 * writes during a WOPI request carries the request URL, access token included, so warnings
 * that callers can trigger on every request must not repeat for each one.
 */
final class LogThrottle {
	public function __construct(
		private ICacheFactory $cacheFactory,
	) {
	}

	public function allows(string $key, int $periodSeconds): bool {
		$cache = $this->cacheFactory->createLocal('office-log-throttle');
		if ($cache->get($key) !== null) {
			return false;
		}

		$cache->set($key, true, $periodSeconds);
		return true;
	}
}
