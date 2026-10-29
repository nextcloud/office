import { beforeEach, describe, expect, it, vi } from 'vitest'

const existsMock = vi.fn()

vi.mock('@nextcloud/files/dav', () => ({
	getClient: vi.fn(() => ({ exists: existsMock })),
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
		expect(existsMock).toHaveBeenCalledWith('/files/alice/New document.docx')
	})

	it('checks the name in a nested folder', async () => {
		existsMock.mockResolvedValue(false)

		await expect(nameExists('/Team folder/Reports', 'Report.odt')).resolves.toBe(false)
		expect(existsMock).toHaveBeenCalledWith('/files/alice/Team folder/Reports/Report.odt')
	})

	it('propagates a failed check to the caller', async () => {
		existsMock.mockRejectedValue(new Error('network down'))

		await expect(nameExists('/', 'New document.docx')).rejects.toThrow('network down')
	})
})
