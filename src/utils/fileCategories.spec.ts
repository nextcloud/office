import { beforeEach, describe, expect, it, vi } from 'vitest'
import { loadState } from '@nextcloud/initial-state'
import { CREATOR_CATEGORIES, fakeLoadState, makeCreator } from '../test-utils/fixtures.ts'
import { allOfficeMimes, categoryId, categoryMimes, categoryName, creatorById } from './fileCategories.ts'

function mockLoadState(options?: Parameters<typeof fakeLoadState>[0]) {
	vi.mocked(loadState).mockImplementation(fakeLoadState(options))
}

const documentsCreator = makeCreator({ extension: '.odt' })
const spreadsheetsCreator = makeCreator({
	label: 'Spreadsheet',
	extension: '.ods',
	mimetypes: ['application/vnd.oasis.opendocument.spreadsheet'],
})

beforeEach(() => {
	mockLoadState()
})

describe('categoryName', () => {
	it('takes the label the server provided for the creator', () => {
		expect(categoryName(spreadsheetsCreator)).toBe('Spreadsheets')
	})

	it('falls back to the creator label when the server sent no category for it', () => {
		const creator = makeCreator({ app: 'customapp', label: 'Custom App', extension: '.xyz' })
		expect(categoryName(creator)).toBe('Custom App')
	})

	it('falls back to the creator label when there is no initial state at all', () => {
		mockLoadState({ categories: [] })
		expect(categoryName(spreadsheetsCreator)).toBe('Spreadsheet')
	})
})

describe('categoryId', () => {
	it('takes the id the server provided for the creator', () => {
		expect(categoryId(spreadsheetsCreator)).toBe('spreadsheets')
	})

	// The id lands in the URL, so it must not move when the admin flips
	// doc_format: the server maps both creators of a category onto one id.
	it('is the same for the ODF and the OOXML creator of one category', () => {
		mockLoadState({
			categories: [
				...CREATOR_CATEGORIES,
				{ ...CREATOR_CATEGORIES[0], extension: '.docx' },
			],
		})
		const ooxml = makeCreator({
			extension: '.docx',
			mimetypes: ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
		})

		expect(categoryId(ooxml)).toBe(categoryId(documentsCreator))
	})

	it('falls back to app and extension for a creator the server sent no category for', () => {
		const creator = makeCreator({ app: 'customapp', extension: '.xyz' })
		expect(categoryId(creator)).toBe('customapp-xyz')
	})

	// The label is translated, so it must never leak into the id.
	it('does not derive the fallback id from the (localised) label', () => {
		const creator = makeCreator({ app: 'customapp', label: 'Dokument', extension: '.xyz' })
		expect(categoryId(creator)).not.toContain('Dokument')
	})
})

describe('creatorById', () => {
	it('finds the creator whose category id matches', () => {
		expect(creatorById([documentsCreator, spreadsheetsCreator], 'spreadsheets')).toBe(spreadsheetsCreator)
	})

	it('returns null for an id no creator matches', () => {
		expect(creatorById([documentsCreator, spreadsheetsCreator], 'nonsense')).toBeNull()
	})

	it('returns null when there is no id at all', () => {
		expect(creatorById([documentsCreator], null)).toBeNull()
	})

	// Two suites can both offer documents; the first one wins so a link always
	// resolves to exactly one creator.
	it('returns the first of two creators sharing a category', () => {
		const other = makeCreator({ app: 'otheroffice' })
		mockLoadState({
			categories: [...CREATOR_CATEGORIES, { ...CREATOR_CATEGORIES[0], app: 'otheroffice' }],
		})

		expect(creatorById([documentsCreator, other], 'documents')).toBe(documentsCreator)
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
		const mimes = categoryMimes(documentsCreator)
		expect(mimes.filter(m => m === 'application/vnd.oasis.opendocument.text')).toHaveLength(1)
	})

	it('keeps the creator\'s own mimes when the server sent no category for it', () => {
		const creator = makeCreator({ app: 'customapp', extension: '.xyz', mimetypes: ['application/x-custom'] })
		expect(categoryMimes(creator)).toEqual(['application/x-custom'])
	})
})

describe('allOfficeMimes', () => {
	it('collects every category\'s mimes without duplicates', () => {
		const mimes = allOfficeMimes()

		expect(mimes).toContain('application/vnd.oasis.opendocument.text')
		expect(mimes).toContain('application/vnd.ms-excel')
		expect(mimes).toContain('application/vnd.oasis.opendocument.graphics')
		expect(new Set(mimes).size).toBe(mimes.length)
	})

	it('is empty without the initial state, leaving the search to the creators\' own mimes', () => {
		mockLoadState({ categories: [] })
		expect(allOfficeMimes()).toEqual([])
	})
})
