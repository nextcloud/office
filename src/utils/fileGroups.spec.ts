import { describe, expect, it } from 'vitest'
import { File } from '@nextcloud/files'
import { makeNode } from '../test-utils/fixtures.ts'
import { groupFilesByAge } from './fileGroups.ts'

// Mid-afternoon, so "today" has hours on both sides of it and a same-day file
// earlier than `now` still groups as today.
const NOW = new Date('2024-06-15T14:30:00')

function daysAgo(days: number, hour = 12) {
	const date = new Date(NOW)
	date.setDate(date.getDate() - days)
	date.setHours(hour, 0, 0, 0)
	return date
}

function labelsOf(files: Parameters<typeof groupFilesByAge>[0]) {
	return groupFilesByAge(files, NOW).map(group => group.label)
}

describe('groupFilesByAge > bucket boundaries', () => {
	// Tuple order is (when, expected, mtime) so it.each's positional %s
	// placeholders pick up the two human-readable strings for the test title.
	it.each([
		['this morning', 'Today', daysAgo(0, 1)],
		['a moment ago', 'Today', daysAgo(0, 14)],
		['yesterday', 'Yesterday', daysAgo(1)],
		['two days ago', 'Previous 7 days', daysAgo(2)],
		['seven days ago', 'Previous 7 days', daysAgo(7)],
		['eight days ago', 'Previous 30 days', daysAgo(8)],
		['thirty days ago', 'Previous 30 days', daysAgo(30)],
		['thirty-one days ago', 'Older', daysAgo(31)],
		['years ago', 'Older', new Date('2019-01-01T00:00:00')],
	])('puts a file modified %s under "%s"', (_when, expected, mtime) => {
		expect(labelsOf([makeNode({ mtime })])).toEqual([expected])
	})

	it('groups by local calendar day, not by a rolling 24 hours', () => {
		// 00:30 today is only 14 hours before NOW, but it is still "today";
		// 23:30 yesterday is 15 hours before NOW and must not be.
		const earlyToday = makeNode({ basename: 'early.odt', mtime: daysAgo(0, 0) })
		const lateYesterday = makeNode({ basename: 'late.odt', mtime: daysAgo(1, 23) })

		expect(labelsOf([earlyToday, lateYesterday])).toEqual(['Today', 'Yesterday'])
	})

	it('treats a future mtime from clock skew as today rather than dropping it', () => {
		const skewed = makeNode({ mtime: new Date('2024-06-15T23:59:00') })

		expect(labelsOf([skewed])).toEqual(['Today'])
	})

	it('puts a node with no mtime under "Older" instead of discarding it', () => {
		// Built directly rather than via makeNode(): its `mtime` parameter has a
		// destructuring default, so passing undefined would hand back that default
		// date instead of a node with no mtime at all.
		const undated = new File({
			id: 1,
			source: 'https://cloud.example.com/remote.php/dav/files/alice/undated.odt',
			root: '/files/alice',
			owner: 'alice',
			mime: 'application/vnd.oasis.opendocument.text',
		})

		expect(groupFilesByAge([undated], NOW)).toEqual([
			{ id: 'older', label: 'Older', files: [undated] },
		])
	})
})

describe('groupFilesByAge > group assembly', () => {
	it('returns groups newest-window first, regardless of input order', () => {
		const old = makeNode({ basename: 'old.odt', mtime: daysAgo(40) })
		const today = makeNode({ basename: 'today.odt', mtime: daysAgo(0) })
		const lastWeek = makeNode({ basename: 'week.odt', mtime: daysAgo(4) })

		expect(labelsOf([old, today, lastWeek])).toEqual(['Today', 'Previous 7 days', 'Older'])
	})

	it('omits empty groups', () => {
		const today = makeNode({ mtime: daysAgo(0) })
		const ancient = makeNode({ mtime: daysAgo(400) })

		// Neither "Yesterday" nor "Previous 7/30 days" has a file.
		expect(labelsOf([today, ancient])).toEqual(['Today', 'Older'])
	})

	it('preserves the caller\'s order within a group', () => {
		// groupFilesByAge is fed an already-sorted list; it must not re-sort.
		const first = makeNode({ basename: 'first.odt', mtime: daysAgo(3, 1) })
		const second = makeNode({ basename: 'second.odt', mtime: daysAgo(3, 23) })

		const [group] = groupFilesByAge([first, second], NOW)

		expect(group.files.map(f => f.basename)).toEqual(['first.odt', 'second.odt'])
	})

	it('returns no groups for an empty list', () => {
		expect(groupFilesByAge([], NOW)).toEqual([])
	})
})
