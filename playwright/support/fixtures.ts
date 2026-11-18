/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { User } from '@nextcloud/e2e-test-server'

import { createRandomUser, login } from '@nextcloud/e2e-test-server/playwright'
import { test as base } from '@playwright/test'
import { readFile } from 'node:fs/promises'

export interface OfficeFixtures {
	account: User
	upload: (fixture: string, target?: string) => Promise<void>
}

/**
 * A fresh user per test, logged in, with a helper to upload files from
 * `playwright/support/files/` into their root folder.
 */
export const test = base.extend<OfficeFixtures>({
	// eslint-disable-next-line no-empty-pattern
	account: async ({}, use) => {
		await use(await createRandomUser())
	},
	page: async ({ account, browser, baseURL }, use) => {
		const page = await browser.newPage({ storageState: undefined, baseURL })
		await login(page.request, account)
		// Cookie-authenticated DAV and OCS requests need the CSRF token.
		const { token } = await (await page.request.get('./csrftoken', { failOnStatusCode: true })).json()
		await page.context().setExtraHTTPHeaders({ requesttoken: token })
		await use(page)
		await page.close()
	},
	upload: async ({ account, page }, use) => {
		await use(async (fixture, target = fixture) => {
			const data = await readFile(new URL(`./files/${fixture}`, import.meta.url))
			await page.request.put(`../remote.php/dav/files/${account.userId}/${target}`, {
				data,
				failOnStatusCode: true,
			})
		})
	},
})

export { expect } from '@playwright/test'
