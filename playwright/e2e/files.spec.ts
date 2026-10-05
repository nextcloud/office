/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures.ts'

test.beforeEach(async ({ upload }) => {
	await upload('document.odt')
	await upload('report.docx')
	await upload('spreadsheet.ods')
	await upload('presentation.odp')
})

test('shows each file in its category only', async ({ page }) => {
	const files = page.locator('.office-overview__files')

	await page.goto('apps/office/documents')
	await expect(files.getByText('document.odt')).toBeVisible()
	await expect(files.getByText('report.docx')).toBeVisible()
	await expect(files.getByText('spreadsheet.ods')).toHaveCount(0)

	await page.goto('apps/office/spreadsheets')
	await expect(files.getByText('spreadsheet.ods')).toBeVisible()
	await expect(files.getByText('document.odt')).toHaveCount(0)

	await page.goto('apps/office/presentations')
	await expect(files.getByText('presentation.odp')).toBeVisible()
})

test('searches within the active category', async ({ page }) => {
	await page.goto('apps/office/documents')
	const files = page.locator('.office-overview__files')
	await expect(files.getByText('document.odt')).toBeVisible()

	await page.getByRole('searchbox', { name: 'Search Documents' }).fill('report')

	await expect(files.getByText('report.docx')).toBeVisible()
	await expect(files.getByText('document.odt')).toHaveCount(0)
})

test('filters by ownership and starred', async ({ page }) => {
	await page.goto('apps/office/documents')
	const files = page.locator('.office-overview__files')
	const filters = page.getByRole('group', { name: 'Filter files' })

	await expect(files.getByText('document.odt')).toBeVisible()

	await filters.getByRole('button', { name: 'Shared with me' }).click()
	await expect(page.getByText('No Documents found')).toBeVisible()

	await filters.getByRole('button', { name: 'Starred' }).click()
	await expect(page.getByRole('heading', { name: 'Starred Documents' })).toBeVisible()
	await expect(page.getByText('No Documents found')).toBeVisible()

	await filters.getByRole('button', { name: 'All' }).click()
	await expect(files.getByText('document.odt')).toBeVisible()
})

test('defaults to grid view and remembers switching to list', async ({ page }) => {
	await page.goto('apps/office/documents')
	const files = page.locator('.office-overview__files')
	await expect(files.getByText('document.odt')).toBeVisible()
	await expect(page.locator('.office-overview__grid')).toBeVisible()

	await page.getByRole('button', { name: 'Switch to list view' }).click()
	await expect(page.locator('.office-overview__list')).toBeVisible()
	await expect(page.getByRole('button', { name: 'Switch to grid view' })).toBeVisible()

	await page.reload()
	await expect(page.locator('.office-overview__list')).toBeVisible()

	await page.getByRole('button', { name: 'Switch to grid view' }).click()
	await expect(page.locator('.office-overview__grid')).toBeVisible()
})
