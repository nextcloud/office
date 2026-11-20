import { beforeEach, describe, expect, it, vi } from 'vitest'

const statMock = vi.fn()

vi.mock('@nextcloud/files/dav', async () => ({
	getClient: vi.fn(() => ({ stat: statMock })),
	getDefaultPropfind: vi.fn(() => '<propfind/>'),
	getRootPath: vi.fn(() => '/files/alice'),
	parsePermissions: (await vi.importActual<typeof import('@nextcloud/files/dav')>('@nextcloud/files/dav')).parsePermissions,
}))

const { canCreateIn } = await import('./folders.ts')

beforeEach(() => {
	statMock.mockReset()
})

// Shaped like the webdav client's detailed stat result for a PROPFIND answer.
function statResult(type: 'directory' | 'file', permissions: string) {
	return { data: { filename: '/files/alice/x', basename: 'x', type, props: { permissions } } }
}

describe('canCreateIn', () => {
	it('stats the folder itself with the default propfind', async () => {
		statMock.mockResolvedValue(statResult('directory', 'RGDNVCK'))

		await canCreateIn('/Documents')

		expect(statMock).toHaveBeenCalledWith('/files/alice/Documents/', { details: true, data: '<propfind/>' })
	})

	it('is true for a folder the user may create in', async () => {
		statMock.mockResolvedValue(statResult('directory', 'RGDNVCK'))

		await expect(canCreateIn('/Documents')).resolves.toBe(true)
	})

	it('is true for a team folder mount with create permission but without delete/rename', async () => {
		statMock.mockResolvedValue(statResult('directory', 'MGCK'))

		await expect(canCreateIn('/Team Folders/Project')).resolves.toBe(true)
	})

	it('is false for a read-only folder, e.g. a read-only share or team folder', async () => {
		statMock.mockResolvedValue(statResult('directory', 'RG'))

		await expect(canCreateIn('/Read-only share')).resolves.toBe(false)
	})

	it('is false for a file, even with full permissions', async () => {
		statMock.mockResolvedValue(statResult('file', 'RGDNVW'))

		await expect(canCreateIn('/Notes.md')).resolves.toBe(false)
	})

	it('is false when the server sends no permissions', async () => {
		statMock.mockResolvedValue({ data: { type: 'directory', props: {} } })

		await expect(canCreateIn('/Odd')).resolves.toBe(false)
	})

	it('propagates a failed stat, e.g. a deleted folder', async () => {
		statMock.mockRejectedValue(new Error('404 Not Found'))

		await expect(canCreateIn('/Gone')).rejects.toThrow('404 Not Found')
	})
})
