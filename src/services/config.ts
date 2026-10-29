/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'
import { getBuilder } from '@nextcloud/browser-storage'

const GRID_VIEW_KEY = 'office.overview.gridView'

export function getOverviewGridView(): boolean {
	return localStorage.getItem(GRID_VIEW_KEY) !== 'false'
}

export function setOverviewGridView(enabled: boolean): void {
	localStorage.setItem(GRID_VIEW_KEY, String(enabled))
}

// The folder new files were last created in, as a path relative to the user's
// Files root. A folder path is the user's data: it lives in Nextcloud's scoped
// storage, which the server clears on logout, and is keyed by user id so the
// next user of the same browser never sees it, even if nobody logged out.
// Callers must re-check that the folder still exists and is writable.
const targetFolderStorage = getBuilder('office').persist().clearOnLogout().build()

function targetFolderKey(): string {
	return 'create.targetFolder.' + (getCurrentUser()?.uid ?? '')
}

// Anything but a plain absolute path ("/", "/Documents") falls back to the
// root folder; ".." and empty segments never reach a request.
function isPlainAbsolutePath(path: string): boolean {
	return path === '/' || (path.startsWith('/') && path.slice(1).split('/').every(segment => segment !== '' && segment !== '.' && segment !== '..'))
}

export function getLastTargetFolder(): string {
	const path = targetFolderStorage.getItem(targetFolderKey())
	return path !== null && isPlainAbsolutePath(path) ? path : '/'
}

export function setLastTargetFolder(path: string): void {
	targetFolderStorage.setItem(targetFolderKey(), path)
}
