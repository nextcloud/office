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

class Version1000Date20260522000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('office_wopi')) {
			return null;
		}

		$table = $schema->createTable('office_wopi');

		$table->addColumn('id', 'bigint', [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('owner_uid', 'string', [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('editor_uid', 'string', [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('guest_displayname', 'string', [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('fileid', 'bigint', [
			'notnull' => true,
			'length' => 20,
		]);
		$table->addColumn('version', 'string', [
			'notnull' => false,
			'length' => 1024,
			'default' => '0',
		]);
		$table->addColumn('canwrite', 'boolean', [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('server_host', 'string', [
			'notnull' => true,
			'default' => 'localhost',
		]);
		$table->addColumn('token', 'string', [
			'notnull' => false,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('expiry', 'bigint', [
			'notnull' => false,
			'length' => 20,
			'unsigned' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'office_wopi_token_idx');
		$table->addIndex(['fileid'], 'office_wopi_fileid_idx');

		return $schema;
	}
}
