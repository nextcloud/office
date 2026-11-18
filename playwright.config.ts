/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: './playwright/e2e',
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	// One shared SQLite-backed server: parallel user creation races on it.
	workers: 1,
	timeout: 60_000,
	reporter: process.env.CI ? [['github'], ['list'], ['html', { open: 'never' }]] : 'list',
	use: {
		baseURL: process.env.baseURL ?? 'http://localhost:8089/index.php/',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
	webServer: {
		command: 'node playwright/start-nextcloud-server.mjs',
		// Pulling and configuring Nextcloud and Collabora takes a while.
		timeout: 10 * 60 * 1000,
		stdout: 'pipe',
		stderr: 'pipe',
		wait: { stdout: /Office e2e environment is ready/ },
		gracefulShutdown: { signal: 'SIGTERM', timeout: 10_000 },
	},
})
