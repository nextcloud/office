import { describe, expect, it } from 'vitest'
import { makeNode } from '../test-utils/fixtures.ts'
import { filterFiles } from './fileFilters.ts'

const DOC_MIME = 'application/vnd.oasis.opendocument.text'
const baseOptions = { currentUid: 'alice', searchQuery: '', category: [DOC_MIME] }

describe('filterFiles > mine', () => {
	it('includes a file owned by currentUid with no mount-type', () => {
		const file = makeNode({ owner: 'alice' })
		expect(filterFiles([file], { ...baseOptions, activeFilter: 'mine' })).toEqual([file])
	})

	it.each(['group', 'shared', 'external', 'external-session'])(
		'excludes a %s-mounted file even when owner equals currentUid',
		(mountType) => {
			// Regression case for #46: external storage's getOwner() falls back to
			// whoever is browsing, so owner === currentUid alone is not "mine".
			const file = makeNode({ owner: 'alice', mountType })
			expect(filterFiles([file], { ...baseOptions, activeFilter: 'mine' })).toEqual([])
		},
	)

	it('excludes a file owned by someone else', () => {
		const file = makeNode({ owner: 'bob' })
		expect(filterFiles([file], { ...baseOptions, activeFilter: 'mine' })).toEqual([])
	})
})

describe('filterFiles > shared', () => {
	it('includes only files mounted as "shared", regardless of owner', () => {
		const shared = makeNode({ owner: 'bob', mountType: 'shared' })
		const mine = makeNode({ owner: 'alice' })
		const external = makeNode({ owner: 'alice', mountType: 'external' })

		expect(filterFiles([shared, mine, external], { ...baseOptions, activeFilter: 'shared' })).toEqual([shared])
	})
})

describe('filterFiles > all', () => {
	it('applies no owner/mount-type filtering', () => {
		const mine = makeNode({ owner: 'alice' })
		const someoneElses = makeNode({ owner: 'bob' })
		const external = makeNode({ owner: 'alice', mountType: 'external' })

		const result = filterFiles([mine, someoneElses, external], { ...baseOptions, activeFilter: 'all' })

		expect(result).toEqual(expect.arrayContaining([mine, someoneElses, external]))
		expect(result).toHaveLength(3)
	})
})

describe('filterFiles > search', () => {
	it('narrows by case-insensitive basename substring on top of the active filter', () => {
		const report = makeNode({ owner: 'alice', basename: 'Annual Report.odt' })
		const notes = makeNode({ owner: 'alice', basename: 'Notes.odt' })

		const result = filterFiles([report, notes], { ...baseOptions, activeFilter: 'all', searchQuery: 'report' })

		expect(result).toEqual([report])
	})
})

describe('filterFiles > category', () => {
	it('only includes files whose mime is in the given category', () => {
		const doc = makeNode({ owner: 'alice', mime: DOC_MIME })
		const image = makeNode({ owner: 'alice', mime: 'image/png' })

		expect(filterFiles([doc, image], { ...baseOptions, activeFilter: 'all' })).toEqual([doc])
	})
})

describe('filterFiles > sort', () => {
	// Pins the real @nextcloud/files sortNodes() behaviour, not an assumption:
	// with sortingMode 'mtime', it internally flips sortingOrder (see its own
	// "reverse if sorting by mtime" comment), so our 'asc' call actually
	// produces newest-first among non-favourites.
	it('sorts favourites first, then newest-first among the rest (real sortNodes)', () => {
		const older = makeNode({ owner: 'alice', basename: 'older.odt', mtime: new Date('2024-01-01') })
		const newer = makeNode({ owner: 'alice', basename: 'newer.odt', mtime: new Date('2024-06-01') })
		const favouriteButOld = makeNode({
			owner: 'alice',
			basename: 'favourite.odt',
			mtime: new Date('2023-01-01'),
			favorite: true,
		})

		const result = filterFiles([older, newer, favouriteButOld], { ...baseOptions, activeFilter: 'all' })

		expect(result.map(f => f.basename)).toEqual(['favourite.odt', 'newer.odt', 'older.odt'])
	})
})
