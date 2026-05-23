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

class Version1002Date20260523000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('office_wopi')) {
			return null;
		}

		$table = $schema->getTable('office_wopi');

		if ($table->hasColumn('hide_download')) {
			return null;
		}

		$table->addColumn('hide_download', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
