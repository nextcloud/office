import { loadState } from '@nextcloud/initial-state'
import type { TemplateCreator } from '../services/templates.ts'

// Provided by PageController::index() from CreatorCategoryService, which owns the
// mapping. Absent when the page was not rendered by this app, in which case every
// lookup below degrades to what the creator itself advertises.
export interface CreatorCategory {
	app: string
	extension: string
	id: string
	label: string
	mimetypes: string[]
}

function categories(): CreatorCategory[] {
	const state = loadState<CreatorCategory[]>('office', 'creator-categories', [])
	return Array.isArray(state) ? state : []
}

// App and extension identify a creator across the templates API and the initial
// state; neither carries an id of its own.
function categoryFor(creator: TemplateCreator): CreatorCategory | null {
	return categories().find(category =>
		category.app === creator.app && category.extension === creator.extension,
	) ?? null
}

export function categoryName(creator: TemplateCreator): string {
	return categoryFor(creator)?.label ?? creator.label
}

// The creator's identity on the URL.
export function categoryId(creator: TemplateCreator): string {
	return categoryFor(creator)?.id
		?? `${creator.app}-${creator.extension.replace(/^\./, '')}`
}

// Two creators can share a category (two suites both offering documents): the
// navigation lists both, the URL addresses the first.
export function creatorById(creators: TemplateCreator[], id: string | null): TemplateCreator | null {
	if (!id) return null
	return creators.find(creator => categoryId(creator) === id) ?? null
}

// All mimetypes belonging to the creator's category (both ODF and OOXML), so a
// category shows every openable file regardless of the configured create format.
// The creator's own mimes are always kept, so anything it advertises beyond the
// server's map (and any creator mapping to no known category) is still covered.
export function categoryMimes(creator: TemplateCreator): string[] {
	return [...new Set([...(categoryFor(creator)?.mimetypes ?? []), ...creator.mimetypes])]
}

// Every office mimetype we can open, regardless of the configured create format
// (doc_format). Creators only advertise the create-format mimes (e.g. OOXML when
// doc_format=ooxml), so searching has to use the full set of every category —
// otherwise existing ODF files would never be found.
export function allOfficeMimes(): string[] {
	return [...new Set(categories().flatMap(category => category.mimetypes))]
}
