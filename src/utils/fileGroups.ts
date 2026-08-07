/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import type { Node } from '@nextcloud/files'

export interface FileGroup {
	/** Stable key for `:key` and the heading's `id` — never shown to the user. */
	id: string
	label: string
	files: Node[]
}

const DAY_MS = 24 * 60 * 60 * 1000

// Rolling windows anchored on local midnight, not calendar week/month: "This
// week" would collapse to an empty group every Monday morning and depends on
// the locale's first day of the week, neither of which a "what did I touch
// recently" list wants.
//
// Ordered newest window first and matched first-fit, so `maxAgeInDays` reads as
// "modified within the last N days" — 7 covers the days before yesterday, since
// today and yesterday match earlier.
const BUCKETS = [
	{ id: 'today', label: t('office', 'Today'), maxAgeInDays: 0 },
	{ id: 'yesterday', label: t('office', 'Yesterday'), maxAgeInDays: 1 },
	{ id: 'previous-7-days', label: t('office', 'Previous 7 days'), maxAgeInDays: 7 },
	{ id: 'previous-30-days', label: t('office', 'Previous 30 days'), maxAgeInDays: 30 },
]

// Catch-all for everything past the last window, and for nodes the server
// returned without an mtime — those sort last rather than disappearing.
const OLDER = { id: 'older', label: t('office', 'Older') }

function startOfLocalDay(date: Date): number {
	const start = new Date(date)
	start.setHours(0, 0, 0, 0)
	return start.getTime()
}

/**
 * Split a list of files into date-labelled groups for display.
 *
 * Order within a group is the caller's order, so `filterFiles()`'s
 * favourites-first, newest-first sorting still holds inside each group. Note
 * that favourites are therefore no longer pinned to the top of the whole list:
 * a starred file from last year belongs under "Older", or the heading above it
 * would be untrue.
 *
 * Empty groups are dropped, so a category with only old files renders a single
 * "Older" heading rather than four empty ones.
 *
 * @param files nodes in display order
 * @param now injected clock — keeps the boundary maths testable without fake timers
 */
export function groupFilesByAge(files: Node[], now: Date = new Date()): FileGroup[] {
	const todayStart = startOfLocalDay(now)
	const grouped = new Map<string, Node[]>()

	for (const file of files) {
		// Missing mtime sorts as infinitely old, so it falls through to OLDER.
		const mtime = file.mtime?.getTime() ?? -Infinity
		const bucket = BUCKETS.find(({ maxAgeInDays }) => mtime >= todayStart - maxAgeInDays * DAY_MS) ?? OLDER

		const existing = grouped.get(bucket.id)
		if (existing) {
			existing.push(file)
		} else {
			grouped.set(bucket.id, [file])
		}
	}

	return [...BUCKETS, OLDER]
		.map(({ id, label }) => ({ id, label, files: grouped.get(id) ?? [] }))
		.filter(group => group.files.length > 0)
}
