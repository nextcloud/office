/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'
import { getClient, getDavNameSpaces, getDavProperties, getRootPath, resultToNode } from '@nextcloud/files/dav'

// The DAV SEARCH is capped server-side via <d:limit> to MAX_DISPLAY_FILES and ordered
// newest-first via <d:orderby>, so the server only returns (and we only transfer) the
// most recently modified results rather than the full collection.
export const MAX_DISPLAY_FILES = 500

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
		<d:orderby>
			<d:order>
				<d:prop>
					<d:getlastmodified/>
				</d:prop>
				<d:descending/>
			</d:order>
		</d:orderby>
		<d:limit>
			<d:nresults>${MAX_DISPLAY_FILES}</d:nresults>
		</d:limit>
	</d:basicsearch>
</d:searchrequest>`
}

// Single flat cache for all office files. Safe because the sole caller (fetchAll)
// always passes the full union of every creator's mimes. If a partial-mime caller
// is ever added this must be keyed by the mimes set.
let cachedNodes: Node[] | null = null

export async function getAllOfficeFiles(mimes: string[]): Promise<Node[]> {
	if (cachedNodes) {
		return cachedNodes
	}

	const client = getClient()
	const response = await client.search('/', {
		details: true,
		data: buildOfficeMimeSearch(mimes),
	}) as { data: { results: object[] } }

	cachedNodes = response.data.results
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
