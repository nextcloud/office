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

// getClient() builds a new client and subscribes it to request-token updates on
// every call, so one client is shared by all checks.
let client: ReturnType<typeof getClient> | undefined

function davClient(): ReturnType<typeof getClient> {
	client ??= getClient()
	return client
}

// Whether `name` is already taken in `folder`, by a file or a folder. One
// depth-0 PROPFIND; a missing entry is the 404 that exists() turns into false,
// any other failure is thrown. An aborted `signal` cancels the request, which
// then rejects.
export async function nameExists(folder: string, name: string, signal?: AbortSignal): Promise<boolean> {
	return davClient().exists(davPath(folder, name), { signal })
}
