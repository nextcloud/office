<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

use OCP\Files\File;

/**
 * What a WOPI token may do right now: the file and the effective rights.
 */
final class WopiAccess {
	public function __construct(
		public readonly File $file,
		public readonly bool $canWrite,
		public readonly bool $hideDownload,
	) {
	}
}
