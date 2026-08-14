/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { makeNode } from '../test-utils/fixtures.ts'
import FilePreview from './FilePreview.vue'

describe('FilePreview', () => {
	it('requests a preview at the given size, keyed by fileid and etag', () => {
		const file = makeNode({ basename: 'report.odt' })
		// `attributes` is a getter returning a live reference — mutate in place,
		// it has no setter (Node/File from @nextcloud/files).
		Object.assign(file.attributes, { etag: 'abcdef1234567890' })

		const wrapper = mount(FilePreview, { props: { file, size: 96 } })

		const img = wrapper.find('img')
		expect(img.attributes('src')).toContain(`fileId=${file.fileid}`)
		expect(img.attributes('src')).toContain('x=96')
		expect(img.attributes('src')).toContain('y=96')
		// Only the first 6 chars of the etag are used for cache-busting.
		expect(img.attributes('src')).toContain('v=abcdef')
		expect(img.attributes('src')).not.toContain('1234567890')
	})

	it('passes the alt text through, defaulting to empty (decorative)', () => {
		const withoutAlt = mount(FilePreview, { props: { file: makeNode() } })
		expect(withoutAlt.find('img').attributes('alt')).toBe('')

		const withAlt = mount(FilePreview, { props: { file: makeNode(), alt: 'report.odt' } })
		expect(withAlt.find('img').attributes('alt')).toBe('report.odt')
	})

	it('falls back to a document icon on image load failure, sized via fallbackIconSize', async () => {
		const wrapper = mount(FilePreview, { props: { file: makeNode(), fallbackIconSize: 32 } })

		await wrapper.find('img').trigger('error')

		expect(wrapper.find('img').exists()).toBe(false)
		const fallback = wrapper.findComponent({ name: 'NcIconSvgWrapper' })
		expect(fallback.exists()).toBe(true)
		expect(fallback.props('size')).toBe(32)
	})

	it('gives each instance independent failure state (no cross-instance/cross-size leakage)', async () => {
		const file = makeNode()
		const small = mount(FilePreview, { props: { file, size: 96 } })
		const large = mount(FilePreview, { props: { file, size: 300 } })

		await small.find('img').trigger('error')

		expect(small.find('img').exists()).toBe(false)
		expect(large.find('img').exists()).toBe(true)
	})
})
