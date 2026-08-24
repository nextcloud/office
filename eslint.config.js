/*!
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 */

import { defineConfig } from 'eslint/config'
import { recommended } from '@nextcloud/eslint-config'

export default defineConfig([
	...recommended,

	{
		files: ['**/*.spec.ts', 'vitest.*.ts'],
		rules: {
			'@typescript-eslint/consistent-type-imports': 'off',
		},
	},
])
