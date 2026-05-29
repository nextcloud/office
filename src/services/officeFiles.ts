/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'
import { getClient, getDavNameSpaces, getDavProperties, getRootPath, resultToNode } from '@nextcloud/files/dav'

// The DAV SEARCH is ordered newest-first via <d:orderby> and capped server-side to
// MAX_DISPLAY_FILES using Nextcloud's pagination headers (X-NC-Paginate), so we only
// transfer the most recently modified results. The X-NC-Paginate-Total response header
// reports the real match count, letting the UI tell whether more files exist.
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
	</d:basicsearch>
</d:searchrequest>`
}

export interface OfficeFilesResult {
	nodes: Node[]
	/** Total number of matching files on the server, which may exceed nodes.length. */
	total: number
}

// Single flat cache for all office files. Safe because the sole caller (fetchAll)
// always passes the full union of every creator's mimes. If a partial-mime caller
// is ever added this must be keyed by the mimes set.
let cachedResult: OfficeFilesResult | null = null

export async function getAllOfficeFiles(mimes: string[]): Promise<OfficeFilesResult> {
	if (cachedResult) {
		return cachedResult
	}

	const client = getClient()
	const response = await client.search('/', {
		details: true,
		data: buildOfficeMimeSearch(mimes),
		headers: {
			'X-NC-Paginate': 'true',
			'X-NC-Paginate-Count': String(MAX_DISPLAY_FILES),
		},
	}) as { data: { results: object[] }, headers: Record<string, string> }

	const nodes = response.data.results
		.map(item => resultToNode(item as Parameters<typeof resultToNode>[0]))
		.filter(node => node.type === 'file')

	// Header keys are lowercased by the webdav client. Falls back to the number of
	// returned nodes on servers that do not support pagination.
	const reportedTotal = Number.parseInt(response.headers['x-nc-paginate-total'] ?? '', 10)

	cachedResult = {
		nodes,
		total: Number.isNaN(reportedTotal) ? nodes.length : reportedTotal,
	}

	return cachedResult
}

export function invalidateOfficeFilesCache(): void {
	cachedResult = null
}

export function filterByMimes(files: Node[], mimes: string[]): Node[] {
	return files.filter(file => mimes.includes(file.mime ?? ''))
}
