import { sortNodes } from '@nextcloud/files'
import type { Node } from '@nextcloud/files'
import { filterByMimes } from '../services/officeFiles.ts'

export type Filter = 'all' | 'mine' | 'shared'

interface FilterFilesOptions {
	activeFilter: Filter
	currentUid: string | null
	searchQuery: string
	category: string[]
}

// files_external mounts have no real per-mount owner — getOwner() falls back
// to whoever is currently browsing — so every files_external mount-type must
// be excluded here, not just group/shared, or "Mine" shows files nobody
// actually owns to every user who has the mount attached (#46).
const NON_MINE_MOUNT_TYPES = ['group', 'shared', 'external', 'external-session']

export function filterFiles(files: Node[], { activeFilter, currentUid, searchQuery, category }: FilterFilesOptions): Node[] {
	const byCategory = filterByMimes(files, category)

	let filtered = byCategory
	if (activeFilter === 'mine') {
		filtered = byCategory.filter(f =>
			f.owner === currentUid
			&& !NON_MINE_MOUNT_TYPES.includes(f.attributes?.['nc:mount-type'] as string),
		)
	} else if (activeFilter === 'shared') {
		filtered = byCategory.filter(f => f.attributes?.['nc:mount-type'] === 'shared')
	}

	if (searchQuery) {
		const q = searchQuery.toLowerCase()
		filtered = filtered.filter(f => f.basename.toLowerCase().includes(q))
	}

	return sortNodes(filtered, {
		sortFavoritesFirst: true,
		sortingMode: 'mtime',
		// Counterintuitive but correct: sortNodes inverts the order for mtime
		// ('asc' = most recently modified first). 'desc' would show oldest first.
		sortingOrder: 'asc',
	})
}
