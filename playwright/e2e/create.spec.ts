/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures.ts'

test('rejects an invalid filename', async ({ page }) => {
	await page.goto('apps/office/documents')
	await page.getByRole('button', { name: 'Blank' }).click()

	const dialog = page.getByRole('dialog')
	await dialog.getByRole('textbox', { name: 'Filename' }).fill('in/valid.odt')
	await dialog.getByRole('button', { name: 'Create' }).click()

	await expect(dialog).toBeVisible()
	await expect(dialog.getByText('Filename contains invalid characters')).toBeVisible()
})

test('creates a blank document and opens it', async ({ page, account }) => {
	await page.goto('apps/office/documents')
	await page.getByRole('button', { name: 'Blank' }).click()

	const dialog = page.getByRole('dialog')
	await dialog.getByRole('textbox', { name: 'Filename' }).fill('Created.odt')
	await dialog.getByRole('button', { name: 'Create' }).click()

	// Hands off to the Files app, which opens the editor; see open.spec.ts.
	await expect(page).toHaveURL(/apps\/files\/files\/\d+/)
	const created = await page.request.fetch(`../remote.php/dav/files/${account.userId}/Created.odt`, { method: 'PROPFIND' })
	expect(created.status()).toBe(207)
})
