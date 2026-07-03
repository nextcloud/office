import { describe, expect, it } from 'vitest'
import { validateFilename } from './validateFilename.ts'

describe('validateFilename', () => {
	it('rejects empty or whitespace-only names', () => {
		expect(validateFilename('')).not.toBeNull()
		expect(validateFilename('   ')).not.toBeNull()
	})

	it('rejects names containing a slash, backslash, or null byte', () => {
		expect(validateFilename('a/b.odt')).not.toBeNull()
		expect(validateFilename('a\\b.odt')).not.toBeNull()
		expect(validateFilename('a\x00b.odt')).not.toBeNull()
	})

	it('accepts an ordinary filename', () => {
		expect(validateFilename('report.odt')).toBeNull()
	})
})
