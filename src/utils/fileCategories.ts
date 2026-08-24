import type { TemplateCreator } from '../services/templates.ts'

import { translate as t } from '@nextcloud/l10n'

const MIME_CATEGORIES: Record<string, string> = {
	'application/vnd.oasis.opendocument.text': t('office', 'Documents'),
	'application/vnd.oasis.opendocument.text-template': t('office', 'Documents'),
	'application/msword': t('office', 'Documents'),
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': t('office', 'Documents'),
	'application/vnd.oasis.opendocument.spreadsheet': t('office', 'Spreadsheets'),
	'application/vnd.oasis.opendocument.spreadsheet-template': t('office', 'Spreadsheets'),
	'application/vnd.ms-excel': t('office', 'Spreadsheets'),
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': t('office', 'Spreadsheets'),
	'application/vnd.oasis.opendocument.presentation': t('office', 'Presentations'),
	'application/vnd.oasis.opendocument.presentation-template': t('office', 'Presentations'),
	'application/vnd.ms-powerpoint': t('office', 'Presentations'),
	'application/vnd.openxmlformats-officedocument.presentationml.presentation': t('office', 'Presentations'),
	'application/vnd.oasis.opendocument.graphics': t('office', 'Diagrams'),
	'application/vnd.oasis.opendocument.graphics-template': t('office', 'Diagrams'),
}

// Every office mimetype we can open, regardless of the configured create format
// (doc_format). richdocuments only advertises the create-format mimes per creator
// (e.g. OOXML when doc_format=ooxml), so we drive the search and category filtering
// from this full set instead — otherwise existing ODF files would never be found.
export const ALL_OFFICE_MIMES = Object.keys(MIME_CATEGORIES)

/**
 *
 * @param creator
 */
export function categoryName(creator: TemplateCreator): string {
	for (const mime of (creator.mimetypes ?? [])) {
		if (MIME_CATEGORIES[mime]) { return MIME_CATEGORIES[mime] }
	}
	return creator.label
}

// All mimetypes belonging to the creator's category (both ODF and OOXML), so a
// category shows every openable file regardless of the configured create format.
// The creator's own mimes are always kept, so anything it advertises beyond our
// static map (and any creator mapping to no known category) is still covered.
/**
 *
 * @param creator
 */
export function categoryMimes(creator: TemplateCreator): string[] {
	const category = categoryName(creator)
	const fromCategory = ALL_OFFICE_MIMES.filter((mime) => MIME_CATEGORIES[mime] === category)
	return [...new Set([...fromCategory, ...creator.mimetypes])]
}
