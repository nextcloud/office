/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { makeCreator } from '../test-utils/fixtures.ts'
import { creatorTheme, previewAspectRatio } from './creatorTheme.ts'

describe('creatorTheme', () => {
	it('maps the first known mime to its theme', () => {
		expect(creatorTheme(makeCreator({ mimetypes: ['application/x-unknown', 'application/vnd.ms-powerpoint'] }))).toBe('presentation')
		expect(creatorTheme(makeCreator({ mimetypes: ['application/vnd.ms-excel'] }))).toBe('spreadsheet')
		expect(creatorTheme(makeCreator({ mimetypes: ['application/vnd.oasis.opendocument.graphics'] }))).toBe('drawing')
	})

	it('falls back to document for unmapped or missing mimes', () => {
		expect(creatorTheme(makeCreator({ mimetypes: ['application/x-unknown'] }))).toBe('document')
		expect(creatorTheme(makeCreator({ mimetypes: [] }))).toBe('document')
	})
})

describe('previewAspectRatio', () => {
	it('is landscape 16:9 for presentations and portrait 2:3 otherwise', () => {
		expect(previewAspectRatio(makeCreator({ mimetypes: ['application/vnd.ms-powerpoint'] }))).toBe(16 / 9)
		expect(previewAspectRatio(makeCreator({ mimetypes: ['application/vnd.ms-excel'] }))).toBe(2 / 3)
		expect(previewAspectRatio(makeCreator({ mimetypes: ['application/vnd.oasis.opendocument.text'] }))).toBe(2 / 3)
	})
})
