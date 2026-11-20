<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

/**
 * The editor's current and previous RSA proof keys from discovery's <proof-key>:
 * base64 big-endian modulus and exponent.
 */
final class WopiProofKeys {
	public function __construct(
		public readonly string $modulus,
		public readonly string $exponent,
		public readonly string $oldModulus,
		public readonly string $oldExponent,
	) {
	}
}
