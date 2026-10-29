/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getClient, getRootPath } from '@nextcloud/files/dav'

// Folder paths here are relative to the user's Files root ("/", "/Documents"),
// the same form the templates/create endpoint takes.
function davPath(folder: string, name: string): string {
	return getRootPath() + folder.replace(/\/$/, '') + '/' + name
}

// Whether `name` is already taken in `folder`, by a file or a folder. One
// depth-0 PROPFIND; a missing entry is the 404 that exists() turns into false,
// any other failure is thrown.
export async function nameExists(folder: string, name: string): Promise<boolean> {
	return getClient().exists(davPath(folder, name))
}
