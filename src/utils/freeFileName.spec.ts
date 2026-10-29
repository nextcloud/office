import { describe, expect, it, vi } from 'vitest'
import { findFreeFileName, MAX_NAME_PROBES } from './freeFileName.ts'

function takenNames(...names: string[]) {
	return vi.fn(async (name: string) => names.includes(name))
}

describe('findFreeFileName', () => {
	it('returns the default name when it is free, after a single check', async () => {
		const isTaken = takenNames()

		await expect(findFreeFileName('New document.docx', isTaken)).resolves.toBe('New document.docx')
		expect(isTaken).toHaveBeenCalledTimes(1)
	})

	it('numbers the name like the Files app when the default is taken', async () => {
		const isTaken = takenNames('New document.docx')

		await expect(findFreeFileName('New document.docx', isTaken)).resolves.toBe('New document (1).docx')
	})

	it('skips every numbered copy that is taken, checking one candidate at a time', async () => {
		const isTaken = takenNames('New document.docx', 'New document (1).docx', 'New document (2).docx')

		await expect(findFreeFileName('New document.docx', isTaken)).resolves.toBe('New document (3).docx')
		expect(isTaken.mock.calls.map(([name]) => name)).toEqual([
			'New document.docx',
			'New document (1).docx',
			'New document (2).docx',
			'New document (3).docx',
		])
	})

	it('keeps dots in the base name and numbers before the extension', async () => {
		const isTaken = takenNames('Q3.2026 report.odt')

		await expect(findFreeFileName('Q3.2026 report.odt', isTaken)).resolves.toBe('Q3.2026 report (1).odt')
	})

	it('gives up after the probe limit and returns the default name', async () => {
		const isTaken = vi.fn(async () => true)

		await expect(findFreeFileName('New document.docx', isTaken)).resolves.toBe('New document.docx')
		expect(isTaken).toHaveBeenCalledTimes(MAX_NAME_PROBES)
	})

	it('propagates a failed check to the caller', async () => {
		const isTaken = vi.fn(async () => { throw new Error('network down') })

		await expect(findFreeFileName('New document.docx', isTaken)).rejects.toThrow('network down')
	})
})
