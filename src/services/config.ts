/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const GRID_VIEW_KEY = 'office.overview.gridView'

export function getOverviewGridView(): boolean {
	return localStorage.getItem(GRID_VIEW_KEY) === 'true'
}

export function setOverviewGridView(enabled: boolean): void {
	localStorage.setItem(GRID_VIEW_KEY, String(enabled))
}
