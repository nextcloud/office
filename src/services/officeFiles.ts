/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'
import { getClient, getDavNameSpaces, getDavProperties, getRootPath, resultToNode } from '@nextcloud/files/dav'
import type { ResponseDataDetailed, SearchResult } from 'webdav'

// TODO: This DAV SEARCH is unpaginated (depth: infinity). For users with very large
// file collections the full result set is transferred over the wire before we slice it.
// MAX_DISPLAY_FILES only guards the rendered list; it does not reduce network cost.
// A proper solution requires a server-side cursor/limit API.
export const MAX_DISPLAY_FILES = 200

function buildOfficeMimeSearch(mimes: string[]): string {
	const escapeXml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
	const conditions = mimes
		.map(mime => `\t\t\t\t<d:eq><d:prop><d:getcontenttype/></d:prop><d:literal>${escapeXml(mime)}</d:literal></d:eq>`)
		.join('\n')

	return `<?xml version="1.0" encoding="UTF-8"?>
<d:searchrequest ${getDavNameSpaces()}>
	<d:basicsearch>
		<d:select>
			<d:prop>
				${getDavProperties()}
			</d:prop>
		</d:select>
		<d:from>
			<d:scope>
				<d:href>${getRootPath()}/</d:href>
				<d:depth>infinity</d:depth>
			</d:scope>
		</d:from>
		<d:where>
			<d:or>
${conditions}
			</d:or>
		</d:where>
	</d:basicsearch>
</d:searchrequest>`
}

let cachedNodes: Node[] | null = null

export async function getAllOfficeFiles(mimes: string[]): Promise<Node[]> {
	if (cachedNodes) {
		return cachedNodes
	}

	const client = getClient()
	const response = await client.search('/', {
		details: true,
		data: buildOfficeMimeSearch(mimes),
	}) as ResponseDataDetailed<SearchResult>

	cachedNodes = (response.data.results as object[])
		.map(item => resultToNode(item as Parameters<typeof resultToNode>[0]))
		.filter(node => node.type === 'file')

	return cachedNodes
}

export function invalidateOfficeFilesCache(): void {
	cachedNodes = null
}

export function filterByMimes(files: Node[], mimes: string[]): Node[] {
	return files.filter(file => mimes.includes(file.mime ?? ''))
}
