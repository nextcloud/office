import { translate as t } from '@nextcloud/l10n'
import type { TemplateCreator } from '../services/templates.ts'

// Category ids appear in the app's URLs (/apps/office/<id>), so they must stay
// stable and locale-independent — hence ids as keys, labels looked up by id.
const CATEGORY_LABELS: Record<string, string> = {
	documents: t('office', 'Documents'),
	spreadsheets: t('office', 'Spreadsheets'),
	presentations: t('office', 'Presentations'),
	diagrams: t('office', 'Diagrams'),
}

const MIME_CATEGORIES: Record<string, string> = {
	'application/vnd.oasis.opendocument.text': 'documents',
	'application/vnd.oasis.opendocument.text-template': 'documents',
	'application/msword': 'documents',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'documents',
	'application/vnd.oasis.opendocument.spreadsheet': 'spreadsheets',
	'application/vnd.oasis.opendocument.spreadsheet-template': 'spreadsheets',
	'application/vnd.ms-excel': 'spreadsheets',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'spreadsheets',
	'application/vnd.oasis.opendocument.presentation': 'presentations',
	'application/vnd.oasis.opendocument.presentation-template': 'presentations',
	'application/vnd.ms-powerpoint': 'presentations',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'presentations',
	'application/vnd.oasis.opendocument.graphics': 'diagrams',
	'application/vnd.oasis.opendocument.graphics-template': 'diagrams',
}

// Every office mimetype we can open, regardless of the configured create format
// (doc_format). richdocuments only advertises the create-format mimes per creator
// (e.g. OOXML when doc_format=ooxml), so we drive the search and category filtering
// from this full set instead — otherwise existing ODF files would never be found.
export const ALL_OFFICE_MIMES = Object.keys(MIME_CATEGORIES)

function creatorCategory(creator: TemplateCreator): string | null {
	for (const mime of (creator.mimetypes ?? [])) {
		if (MIME_CATEGORIES[mime]) return MIME_CATEGORIES[mime]
	}
	return null
}

export function categoryName(creator: TemplateCreator): string {
	const category = creatorCategory(creator)
	return category ? CATEGORY_LABELS[category] : creator.label
}

// The creator's identity on the URL. A category id survives a doc_format switch,
// which changes the creator's extension and mimetypes, so bookmarked links keep
// working. Creators outside the static map fall back to app + extension.
export function categoryId(creator: TemplateCreator): string {
	return creatorCategory(creator)
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
// The creator's own mimes are always kept, so anything it advertises beyond our
// static map (and any creator mapping to no known category) is still covered.
export function categoryMimes(creator: TemplateCreator): string[] {
	const category = creatorCategory(creator)
	const fromCategory = category
		? ALL_OFFICE_MIMES.filter(mime => MIME_CATEGORIES[mime] === category)
		: []
	return [...new Set([...fromCategory, ...creator.mimetypes])]
}
