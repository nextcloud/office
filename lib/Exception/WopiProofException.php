<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Exception;

/**
 * A WOPI request that doesn't carry a valid, current proof from the editor server.
 */
final class WopiProofException extends \Exception {
}
