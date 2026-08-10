import { Folder } from '@nextcloud/files'
import type { Node } from '@nextcloud/files'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { makeNode } from '../test-utils/fixtures.ts'

const searchMock = vi.fn()

vi.mock('@nextcloud/files/dav', () => ({
	getClient: vi.fn(() => ({ search: searchMock })),
	getDavNameSpaces: vi.fn(() => 'xmlns:d="DAV:"'),
	getDavProperties: vi.fn(() => '<d:getcontenttype/>'),
	getRootPath: vi.fn(() => '/remote.php/dav/files/alice'),
	resultToNode: vi.fn((item: unknown) => item),
}))

const { getAllOfficeFiles, invalidateOfficeFilesCache, filterByMimes, SEARCH_RESULT_LIMIT } = await import('./officeFiles.ts')

describe('filterByMimes', () => {
	it('keeps files whose mime is in the list and drops the rest', () => {
		const doc = makeNode({ mime: 'application/vnd.oasis.opendocument.text' })
		const image = makeNode({ mime: 'image/png' })

		expect(filterByMimes([doc, image], ['application/vnd.oasis.opendocument.text'])).toEqual([doc])
	})

	it('treats a missing mime as an empty string rather than throwing', () => {
		const node = { mime: undefined } as unknown as Node

		expect(filterByMimes([node], [''])).toEqual([node])
		expect(filterByMimes([node], ['application/pdf'])).toEqual([])
	})
})

describe('getAllOfficeFiles', () => {
	beforeEach(() => {
		invalidateOfficeFilesCache()
		searchMock.mockReset()
	})

	it('filters out non-file nodes (e.g. folders) from the DAV SEARCH result', async () => {
		const file = makeNode({ basename: 'doc.odt' })
		const folder = new Folder({
			source: 'https://cloud.example.com/remote.php/dav/files/alice/sub',
			root: '/files/alice',
			owner: 'alice',
		})
		searchMock.mockResolvedValue({ data: { results: [file, folder] } })

		const result = await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])

		expect(result).toEqual({ nodes: [file], truncated: false })
	})

	it('orders by mtime descending and requests SEARCH_RESULT_LIMIT rows', async () => {
		searchMock.mockResolvedValue({ data: { results: [] } })

		await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])

		const body = searchMock.mock.calls[0][1].data as string
		expect(body).toContain('<d:prop><d:getlastmodified/></d:prop>')
		expect(body).toContain('<d:descending/>')
		expect(body).toContain(`<d:nresults>${SEARCH_RESULT_LIMIT}</d:nresults>`)
	})

	it('reports truncated when the server returns a full page', async () => {
		const fullPage = Array.from({ length: SEARCH_RESULT_LIMIT }, () => makeNode())
		searchMock.mockResolvedValue({ data: { results: fullPage } })

		const result = await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])

		expect(result.truncated).toBe(true)
	})

	it('reports not truncated when the server returns fewer than the limit', async () => {
		searchMock.mockResolvedValue({ data: { results: [makeNode()] } })

		const result = await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])

		expect(result.truncated).toBe(false)
	})

	it('caches the result across calls, even with a different mimes argument', async () => {
		searchMock.mockResolvedValue({ data: { results: [makeNode()] } })

		await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])
		await getAllOfficeFiles(['image/png'])

		expect(searchMock).toHaveBeenCalledTimes(1)
	})

	it('invalidateOfficeFilesCache forces the next call to re-fetch', async () => {
		searchMock.mockResolvedValue({ data: { results: [makeNode()] } })

		await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])
		invalidateOfficeFilesCache()
		await getAllOfficeFiles(['application/vnd.oasis.opendocument.text'])

		expect(searchMock).toHaveBeenCalledTimes(2)
	})

	it('escapes & < > when building the SEARCH body', async () => {
		searchMock.mockResolvedValue({ data: { results: [] } })

		await getAllOfficeFiles(['a&b<c>d'])

		const body = searchMock.mock.calls[0][1].data as string
		expect(body).toContain('a&amp;b&lt;c&gt;d')
		expect(body).not.toContain('a&b<c>d')
	})
})
