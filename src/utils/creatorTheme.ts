/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { TemplateCreator } from '../services/templates.ts'

export type CreatorTheme = 'document' | 'spreadsheet' | 'presentation' | 'drawing'

const MIME_THEME: Record<string, CreatorTheme> = {
	'application/vnd.oasis.opendocument.presentation': 'presentation',
	'application/vnd.oasis.opendocument.presentation-template': 'presentation',
	'application/vnd.ms-powerpoint': 'presentation',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'presentation',
	'application/vnd.oasis.opendocument.spreadsheet': 'spreadsheet',
	'application/vnd.oasis.opendocument.spreadsheet-template': 'spreadsheet',
	'application/vnd.ms-excel': 'spreadsheet',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'spreadsheet',
	'application/vnd.oasis.opendocument.graphics': 'drawing',
	'application/vnd.oasis.opendocument.graphics-template': 'drawing',
}

export function creatorTheme(creator: TemplateCreator): CreatorTheme {
	for (const mime of (creator.mimetypes ?? [])) {
		const theme = MIME_THEME[mime]
		if (theme) return theme
	}
	return 'document'
}

// Width / height of a document preview: slides are landscape, everything else
// is a portrait page.
export function previewAspectRatio(creator: TemplateCreator): number {
	return creatorTheme(creator) === 'presentation' ? 16 / 9 : 2 / 3
}
