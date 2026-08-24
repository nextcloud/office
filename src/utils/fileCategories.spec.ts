import { describe, expect, it } from 'vitest'
import { makeCreator } from '../test-utils/fixtures.ts'
import { categoryId, categoryMimes, categoryName, creatorById } from './fileCategories.ts'

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

describe('categoryId', () => {
	it('maps a known mime to its category id', () => {
		const creator = makeCreator({ mimetypes: ['application/vnd.ms-excel'] })
		expect(categoryId(creator)).toBe('spreadsheets')
	})

	// The id lands in the URL, so it must not move when the admin flips
	// doc_format — the same category advertises different mimes and extensions
	// then, and a bookmarked link has to keep working.
	it('is the same for the ODF and the OOXML creator of one category', () => {
		const odf = makeCreator({
			extension: '.odt',
			mimetypes: ['application/vnd.oasis.opendocument.text'],
		})
		const ooxml = makeCreator({
			extension: '.docx',
			mimetypes: ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
		})

		expect(categoryId(odf)).toBe(categoryId(ooxml))
	})

	it('falls back to app and extension when no mime maps to a category', () => {
		const creator = makeCreator({
			app: 'customapp',
			extension: '.xyz',
			mimetypes: ['application/x-custom'],
		})
		expect(categoryId(creator)).toBe('customapp-xyz')
	})

	// The label is translated, so it must never leak into the id.
	it('does not derive the fallback id from the (localised) label', () => {
		const creator = makeCreator({ label: 'Dokument', mimetypes: ['application/x-custom'] })
		expect(categoryId(creator)).not.toContain('Dokument')
	})
})

describe('creatorById', () => {
	const documents = makeCreator({ mimetypes: ['application/vnd.oasis.opendocument.text'] })
	const spreadsheets = makeCreator({ mimetypes: ['application/vnd.oasis.opendocument.spreadsheet'] })

	it('finds the creator whose category id matches', () => {
		expect(creatorById([documents, spreadsheets], 'spreadsheets')).toBe(spreadsheets)
	})

	it('returns null for an id no creator matches', () => {
		expect(creatorById([documents, spreadsheets], 'nonsense')).toBeNull()
	})

	it('returns null when there is no id at all', () => {
		expect(creatorById([documents], null)).toBeNull()
	})

	// Two suites can both offer documents; the first one wins so a link always
	// resolves to exactly one creator.
	it('returns the first of two creators sharing a category', () => {
		const other = makeCreator({ app: 'otheroffice', mimetypes: ['application/msword'] })
		expect(creatorById([documents, other], 'documents')).toBe(documents)
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
