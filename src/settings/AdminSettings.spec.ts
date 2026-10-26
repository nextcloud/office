/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import NcButton from '@nextcloud/vue/components/NcButton'
import { beforeEach, describe, expect, it } from 'vitest'
import AdminSettings from './AdminSettings.vue'

function setAdminData(data: Record<string, string>) {
	const el = document.createElement('script')
	el.id = 'office-admin-data'
	el.type = 'application/json'
	el.textContent = JSON.stringify(data)
	document.body.appendChild(el)
}

describe('AdminSettings', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		setAdminData({
			wopi_url: 'http://eo-internal',
			public_wopi_url: 'https://office.example.com',
			callback_url: '',
			disable_certificate_verification: 'no',
		})
	})

	it('renders the form with the stored settings', () => {
		const wrapper = mount(AdminSettings)

		expect(wrapper.find('h2').text()).toBe('Office')
		const values = wrapper.findAll('input[type="url"]').map((input) => (input.element as HTMLInputElement).value)
		expect(values).toEqual(['http://eo-internal', 'https://office.example.com', ''])
	})

	it('styles the save button as the primary action', () => {
		const button = mount(AdminSettings).findComponent(NcButton)

		expect(button.props('variant')).toBe('primary')
	})
})
