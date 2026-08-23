/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { makeNode } from '../test-utils/fixtures.ts'
import ShareIndicator from './ShareIndicator.vue'

describe('ShareIndicator', () => {
	it('renders nothing for an unshared file the current user owns', () => {
		const wrapper = mount(ShareIndicator, {
			props: { file: makeNode({ owner: 'alice' }), currentUid: 'alice' },
		})

		expect(wrapper.find('.share-indicator').exists()).toBe(false)
	})

	it('labels an outgoing share "Shared"', () => {
		const wrapper = mount(ShareIndicator, {
			props: { file: makeNode({ owner: 'alice', shareTypes: [0] }), currentUid: 'alice' },
		})

		const indicator = wrapper.find('.share-indicator')
		expect(indicator.exists()).toBe(true)
		expect(indicator.attributes('aria-label')).toBe('Shared')
		// The label is also the accessible name and the tooltip.
		expect(indicator.attributes('title')).toBe('Shared')
		expect(indicator.attributes('role')).toBe('img')
	})

	it('names the owner for an incoming share', () => {
		const file = makeNode({ owner: 'bob' })
		file.attributes!['owner-display-name'] = 'Bob'

		const wrapper = mount(ShareIndicator, {
			props: { file, currentUid: 'alice' },
		})

		expect(wrapper.find('.share-indicator').attributes('aria-label')).toBe('Shared by Bob')
	})

	it('falls back to "Shared" for an incoming share with no owner display name', () => {
		const wrapper = mount(ShareIndicator, {
			props: { file: makeNode({ owner: 'bob' }), currentUid: 'alice' },
		})

		expect(wrapper.find('.share-indicator').attributes('aria-label')).toBe('Shared')
	})
})
