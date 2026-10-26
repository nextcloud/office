/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSettings from './AdminSettings.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({
	default: { post: postMock },
	isAxiosError: (e: unknown) => (e as { isAxiosError?: boolean })?.isAxiosError === true,
}))

function setAdminData(data: Record<string, string | boolean | null>) {
	const el = document.createElement('script')
	el.id = 'office-admin-data'
	el.type = 'application/json'
	el.textContent = JSON.stringify(data)
	document.body.appendChild(el)
}

describe('AdminSettings', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		postMock.mockReset()
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

	it.each([
		[true, true],
		[false, false],
		[null, false],
	])('warns about unchecked WOPI callers only when the server reports them (%s)', (unchecked, shown) => {
		document.body.innerHTML = ''
		setAdminData({ wopi_url: 'http://eo-internal', public_wopi_url: '', callback_url: '', disable_certificate_verification: 'no', wopi_caller_unchecked: unchecked })

		const wrapper = mount(AdminSettings)

		expect(wrapper.text().includes('does not sign its requests')).toBe(shown)
	})

	it('styles the save button as the primary action', () => {
		const button = mount(AdminSettings).findComponent(NcButton)

		expect(button.props('variant')).toBe('primary')
	})

	it('saves through the router-generated URL so subdirectory installs work', async () => {
		postMock.mockResolvedValue({ data: {} })
		const wrapper = mount(AdminSettings)

		await wrapper.findComponent(NcButton).trigger('click')
		await flushPromises()

		expect(generateUrl).toHaveBeenCalledWith('/apps/office/settings/admin')
		expect(postMock).toHaveBeenCalledWith('/apps/office/settings/admin', {
			wopi_url: 'http://eo-internal',
			public_wopi_url: 'https://office.example.com',
			callback_url: '',
			disable_certificate_verification: 'no',
		})
		expect(wrapper.text()).toContain('Settings saved')
	})

	it('shows the server\'s validation message when saving is rejected', async () => {
		postMock.mockRejectedValue({
			isAxiosError: true,
			message: 'Request failed with status code 400',
			response: { data: { error: 'wopi_url must use the http or https scheme' } },
		})
		const wrapper = mount(AdminSettings)

		await wrapper.findComponent(NcButton).trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('wopi_url must use the http or https scheme')
	})
})
