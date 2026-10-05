/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures.ts'

test('opens an office document in Collabora', async ({ page, upload }) => {
	await upload('document.odt')
	await page.goto('apps/office/documents')

	await page.locator('.office-overview__files').getByText('document.odt').click()

	const collabora = page.frameLocator('[data-cy="coolframe"]')
	await expect(collabora.locator('#main-document-content')).toBeVisible({ timeout: 30_000 })
})

// Server master dropped the bundled Viewer app for @nextcloud/viewer
// (nextcloud/server#63954) and Text does not register with it yet, so Files
// falls back to downloading the file. Re-enable with nextcloud/text#9235.
test.fixme('opens a markdown file in Text', async ({ page, upload }) => {
	await upload('note.md')
	await page.goto('apps/office/')

	await page.getByRole('navigation').getByRole('link', { name: 'Text document' }).click()
	await page.locator('.office-overview__files').getByText('note.md').click()

	await expect(page.locator('.editor').getByRole('textbox')).toContainText('Office e2e note')
})
