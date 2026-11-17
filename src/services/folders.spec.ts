import { beforeEach, describe, expect, it, vi } from 'vitest'

const existsMock = vi.fn()
const getClientMock = vi.fn(() => ({ exists: existsMock }))

vi.mock('@nextcloud/files/dav', () => ({
	getClient: getClientMock,
	getRootPath: vi.fn(() => '/files/alice'),
}))

const { nameExists } = await import('./folders.ts')

beforeEach(() => {
	existsMock.mockReset()
})

describe('nameExists', () => {
	it('checks the name in the root folder without a double slash', async () => {
		existsMock.mockResolvedValue(true)

		await expect(nameExists('/', 'New document.docx')).resolves.toBe(true)
		expect(existsMock).toHaveBeenCalledWith('/files/alice/New document.docx', { signal: undefined })
	})

	it('checks the name in a nested folder', async () => {
		existsMock.mockResolvedValue(false)

		await expect(nameExists('/Team folder/Reports', 'Report.odt')).resolves.toBe(false)
		expect(existsMock).toHaveBeenCalledWith('/files/alice/Team folder/Reports/Report.odt', { signal: undefined })
	})

	it('propagates a failed check to the caller', async () => {
		existsMock.mockRejectedValue(new Error('network down'))

		await expect(nameExists('/', 'New document.docx')).rejects.toThrow('network down')
	})

	it('passes the abort signal on to the request', async () => {
		existsMock.mockResolvedValue(false)
		const controller = new AbortController()

		await nameExists('/', 'New document.docx', controller.signal)

		expect(existsMock).toHaveBeenCalledWith('/files/alice/New document.docx', { signal: controller.signal })
	})

	it('creates the DAV client once and reuses it for every check', async () => {
		// A fresh module, so the client cached by earlier tests does not count.
		vi.resetModules()
		getClientMock.mockClear()
		const { nameExists: freshNameExists } = await import('./folders.ts')
		existsMock.mockResolvedValue(false)

		await freshNameExists('/', 'New document.docx')
		await freshNameExists('/', 'New document (1).docx')
		await freshNameExists('/Documents', 'Report.odt')

		expect(getClientMock).toHaveBeenCalledTimes(1)
	})
})
