<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Support;

use OCP\ICache;

/**
 * An in-memory ICache that behaves like a real memcache; TTLs are ignored.
 */
final class MemoryCache implements ICache {
	/** @var array<string, mixed> */
	private array $values = [];

	public function get(string $key) {
		return $this->values[$key] ?? null;
	}

	public function set(string $key, $value, int $ttl = 0) {
		$this->values[$key] = $value;
		return true;
	}

	public function hasKey(string $key) {
		return array_key_exists($key, $this->values);
	}

	public function remove(string $key) {
		unset($this->values[$key]);
		return true;
	}

	public function clear(string $prefix = '') {
		$this->values = array_filter($this->values, static fn (string $key): bool => !str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}
}
