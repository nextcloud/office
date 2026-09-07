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

class Version1001Date20260523000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('office_wopi_locks')) {
			return null;
		}

		$table = $schema->createTable('office_wopi_locks');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'unsigned' => true,
			'notnull' => true,
		]);
		// One lock per file — fileid is unique.
		$table->addColumn('fileid', Types::BIGINT, [
			'notnull' => true,
		]);
		// Opaque lock ID supplied by the WOPI client (up to 1024 chars per spec).
		$table->addColumn('lock_id', Types::STRING, [
			'length' => 1024,
			'notnull' => true,
		]);
		// Unix timestamp when the lock expires (WOPI locks default to 30 minutes).
		$table->addColumn('expiry', Types::BIGINT, [
			'unsigned' => true,
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['fileid'], 'office_wopi_locks_fileid_idx');

		return $schema;
	}
}
