<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Exception;

/**
 * A WOPI request from an address outside the configured editor allowlist.
 */
final class WopiCallerNotAllowedException extends \Exception {
}
