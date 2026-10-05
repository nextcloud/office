/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures.ts'

test('lists a category per office file type and routes to it', async ({ page }) => {
	await page.goto('apps/office/')

	const navigation = page.getByRole('navigation')
	for (const category of ['Documents', 'Spreadsheets', 'Presentations', 'Diagrams']) {
		await expect(navigation.getByRole('link', { name: category })).toBeVisible()
	}

	await navigation.getByRole('link', { name: 'Spreadsheets' }).click()
	await expect(page).toHaveURL(/apps\/office\/spreadsheets$/)
	await expect(page.getByRole('heading', { name: 'Recent Spreadsheets' })).toBeVisible()
})

test('falls back to the first category for an unknown one', async ({ page }) => {
	await page.goto('apps/office/does-not-exist')

	const first = page.getByRole('navigation').getByRole('link').first()
	await expect(page).toHaveURL(new RegExp(`${await first.getAttribute('href')}$`))
	await expect(first).toHaveAttribute('aria-current', 'page')
})
