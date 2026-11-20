<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Link the tokens of share-link sessions to their share, so every WOPI request
 * can re-check that the share still exists and what it still allows.
 */
final class Version1003Date20261026000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('office_wopi')) {
			return null;
		}

		$table = $schema->getTable('office_wopi');

		if ($table->hasColumn('share_token')) {
			return null;
		}

		$table->addColumn('share_token', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);

		return $schema;
	}
}
