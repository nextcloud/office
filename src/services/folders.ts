/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { Permission } from '@nextcloud/files'
import { getClient, getDefaultPropfind, getRootPath, parsePermissions } from '@nextcloud/files/dav'

// Folder paths here are relative to the user's Files root ("/", "/Documents",
// "/Team folder/Reports"), the same form Node.path and the templates/create
// endpoint use. Team folders are mounted into that tree, so they need no
// special handling.
function davPath(folder: string): string {
	return getRootPath() + folder.replace(/\/$/, '') + '/'
}

// Whether the folder still exists and the user may create files in it. Used to
// re-check a remembered folder, which may have been deleted, unshared or had
// its permissions reduced since it was picked.
export async function canCreateIn(folder: string): Promise<boolean> {
	const response = await getClient().stat(davPath(folder), {
		details: true,
		data: getDefaultPropfind(),
	}) as { data: { type: string, props?: { permissions?: unknown } } }
	const { type, props } = response.data
	// oc:permissions, e.g. "RGDNVCK"; team folders and shares report their own,
	// including groupfolder ACLs.
	return type === 'directory' && (parsePermissions(String(props?.permissions ?? '')) & Permission.CREATE) !== 0
}
