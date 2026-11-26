<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The hourly cleanup selects expired tokens by expiry; without an index that is a
 * full scan of a table anonymous share-link visits add to.
 */
final class Version1004Date20261026000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('office_wopi')) {
			return null;
		}

		$table = $schema->getTable('office_wopi');

		if ($table->hasIndex('office_wopi_expiry_idx')) {
			return null;
		}

		$table->addIndex(['expiry'], 'office_wopi_expiry_idx');

		return $schema;
	}
}
