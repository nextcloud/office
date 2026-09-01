/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'
import { getClient, getDavNameSpaces, getDavProperties, getRootPath, resultToNode } from '@nextcloud/files/dav'

// Upper bound on files rendered per category, after client-side category and
// ownership filtering.
export const MAX_DISPLAY_FILES = 200

// Upper bound on nodes returned to callers, across every category at once.
// Deliberately well above MAX_DISPLAY_FILES: a single search feeds all categories
// and is narrowed further by the Mine/Shared filters, so the fetched set has to be
// large enough that one category is not starved by the others.
//
// This must stay explicit: without a <d:limit> the server applies its own default
// of 100 rows, which is far too small to fill four categories.
export const SEARCH_RESULT_LIMIT = 500

export interface OfficeFilesResult {
	nodes: Node[]
	/**
	 * True when more office files exist than fit in `nodes` (more than
	 * SEARCH_RESULT_LIMIT matched). Callers should offer a way out to the Files
	 * app rather than implying the list is complete.
	 */
	truncated: boolean
}

// The <d:orderby> is what makes the result set the *newest* files rather than an
// arbitrary one: the server only adds an ORDER BY when the request asks for one, so
// without it the limit above would cut the result set at SEARCH_RESULT_LIMIT rows in
// database order, and a recently edited document could be missing from "Recent" entirely.
//
// The request asks for one row more than SEARCH_RESULT_LIMIT so getAllOfficeFiles can
// tell "exactly SEARCH_RESULT_LIMIT files exist" from "more exist and got cut off" —
// both would otherwise come back as exactly SEARCH_RESULT_LIMIT rows.
function buildOfficeMimeSearch(mimes: string[]): string {
	// `mimes` isn't a closed literal set — it includes server-registered
	// mimetypes from the templates endpoint, not just this file's own
	// constants. Escaping only &/</> is still complete here regardless of
	// origin, because the result is only ever interpolated into <d:literal>
	// element *text*, never an attribute; there's no quote or CDATA context
	// to escape for.
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
				<d:prop><d:getlastmodified/></d:prop>
				<d:descending/>
			</d:order>
		</d:orderby>
		<d:limit>
			<d:nresults>${SEARCH_RESULT_LIMIT + 1}</d:nresults>
		</d:limit>
	</d:basicsearch>
</d:searchrequest>`
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
	}) as { data: { results: object[] } }

	const results = response.data.results

	cachedResult = {
		// Trim the extra row the request asked for (see buildOfficeMimeSearch) — it
		// exists to detect truncation, not to be shown.
		nodes: results
			.map(item => resultToNode(item as Parameters<typeof resultToNode>[0]))
			.filter(node => node.type === 'file')
			.slice(0, SEARCH_RESULT_LIMIT),
		// More than SEARCH_RESULT_LIMIT raw rows back means the extra row was actually
		// filled, i.e. more files exist than fit. Measured on the raw count, before the
		// folder filter above, which can only shrink it further.
		truncated: results.length > SEARCH_RESULT_LIMIT,
	}

	return cachedResult
}

export function invalidateOfficeFilesCache(): void {
	cachedResult = null
}

export function filterByMimes(files: Node[], mimes: string[]): Node[] {
	return files.filter(file => mimes.includes(file.mime ?? ''))
}
