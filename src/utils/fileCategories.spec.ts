import { describe, expect, it } from 'vitest'
import { makeCreator } from '../test-utils/fixtures.ts'
import { categoryMimes, categoryName } from './fileCategories.ts'

describe('categoryName', () => {
	it('maps a known mime to its category name', () => {
		const creator = makeCreator({ mimetypes: ['application/vnd.ms-excel'] })
		expect(categoryName(creator)).toBe('Spreadsheets')
	})

	it('falls back to the creator label when no mime maps to a category', () => {
		const creator = makeCreator({ label: 'Custom App', mimetypes: ['application/x-custom'] })
		expect(categoryName(creator)).toBe('Custom App')
	})
})

describe('categoryMimes', () => {
	it('unions the category\'s full ODF+OOXML mime set with the creator\'s own mimes', () => {
		const creator = makeCreator({
			mimetypes: ['application/vnd.oasis.opendocument.text', 'application/x-creator-only'],
		})

		const mimes = categoryMimes(creator)

		expect(mimes).toEqual(expect.arrayContaining([
			'application/vnd.oasis.opendocument.text',
			'application/vnd.oasis.opendocument.text-template',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/x-creator-only',
		]))
		// doesn't leak mimes belonging to a different category
		expect(mimes).not.toContain('application/vnd.ms-excel')
	})

	it('deduplicates mimes that appear in both the category set and the creator\'s own list', () => {
		const creator = makeCreator({ mimetypes: ['application/vnd.oasis.opendocument.text'] })
		const mimes = categoryMimes(creator)
		expect(mimes.filter(m => m === 'application/vnd.oasis.opendocument.text')).toHaveLength(1)
	})
})
