/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { Permission } from '@nextcloud/files'
import type { INode } from '@nextcloud/files'
import { beforeEach, describe, expect, it, vi } from 'vitest'

class FilePickerClosedMock extends Error {}

const pickNodesMock = vi.fn()
const builder = {
	canPick: null as ((node: INode) => boolean) | null,
	buttonFactory: null as ((nodes: INode[]) => { label: string, disabled?: boolean }[]) | null,
	startAt: '',
	mimeTypes: [] as string[],
}

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: FilePickerClosedMock,
	getFilePickerBuilder: vi.fn(() => {
		const chain = {
			setMultiSelect: () => chain,
			allowDirectories: () => chain,
			setMimeTypeFilter: (mimes: string[]) => { builder.mimeTypes = mimes; return chain },
			setCanPick: (fn: (node: INode) => boolean) => { builder.canPick = fn; return chain },
			startAt: (path: string) => { builder.startAt = path; return chain },
			setButtonFactory: (fn: (nodes: INode[]) => { label: string }[]) => { builder.buttonFactory = fn; return chain },
			build: () => ({ pickNodes: pickNodesMock }),
		}
		return chain
	}),
}))

vi.mock('@nextcloud/dialogs/style.css', () => ({}))

const { default: TargetFolderPicker } = await import('./TargetFolderPicker.vue')

function folder(path: string, permissions: number): INode {
	return { path, displayname: path.split('/').pop(), permissions } as unknown as INode
}

async function clickChange(wrapper: ReturnType<typeof mount>) {
	await wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	pickNodesMock.mockReset()
	builder.canPick = null
	builder.buttonFactory = null
	builder.startAt = ''
	builder.mimeTypes = []
})

describe('TargetFolderPicker', () => {
	it('names the root folder "All files"', () => {
		const wrapper = mount(TargetFolderPicker, { props: { path: '/' } })

		expect(wrapper.find('.target-folder-picker__path').text()).toBe('All files')
	})

	it('shows the full path of a nested folder, so equal folder names stay distinguishable', () => {
		const wrapper = mount(TargetFolderPicker, { props: { path: '/Team folder/Reports' } })

		expect(wrapper.find('.target-folder-picker__path').text()).toBe('Team folder/Reports')
	})

	it('gives the change button an accessible name that includes the current folder', () => {
		const wrapper = mount(TargetFolderPicker, { props: { path: '/Documents' } })

		expect(wrapper.findComponent({ name: 'NcButton' }).attributes('aria-label'))
			.toBe('Change folder, currently Documents')
	})

	it('opens a folder-only picker at the current folder', async () => {
		pickNodesMock.mockRejectedValue(new FilePickerClosedMock())
		const wrapper = mount(TargetFolderPicker, { props: { path: '/Documents' } })

		await clickChange(wrapper)

		expect(builder.startAt).toBe('/Documents')
		expect(builder.mimeTypes).toEqual(['httpd/unix-directory'])
	})

	it('only lets folders with create permission be picked, team folders included', async () => {
		pickNodesMock.mockRejectedValue(new FilePickerClosedMock())
		const wrapper = mount(TargetFolderPicker, { props: { path: '/' } })

		await clickChange(wrapper)

		expect(builder.canPick!(folder('/Team folder', Permission.READ | Permission.CREATE))).toBe(true)
		expect(builder.canPick!(folder('/Read-only share', Permission.READ))).toBe(false)
	})

	it('disables the choose button when nothing can be picked', async () => {
		pickNodesMock.mockRejectedValue(new FilePickerClosedMock())
		const wrapper = mount(TargetFolderPicker, { props: { path: '/' } })

		await clickChange(wrapper)

		expect(builder.buttonFactory!([])[0].disabled).toBe(true)
		const [button] = builder.buttonFactory!([folder('/Documents', Permission.ALL)])
		expect(button.disabled).toBe(false)
		expect(button.label).toBe('Choose Documents')
	})

	it('calls the root folder "All files" on the choose button, not by the user id', async () => {
		pickNodesMock.mockRejectedValue(new FilePickerClosedMock())
		const wrapper = mount(TargetFolderPicker, { props: { path: '/' } })

		await clickChange(wrapper)

		const root = { path: '/', displayname: 'alice', permissions: Permission.ALL } as unknown as INode
		expect(builder.buttonFactory!([root])[0].label).toBe('Choose All files')
	})

	it('emits the picked folder path', async () => {
		pickNodesMock.mockResolvedValue([folder('/Team folder/Reports', Permission.ALL)])
		const wrapper = mount(TargetFolderPicker, { props: { path: '/' } })

		await clickChange(wrapper)

		expect(wrapper.emitted('update:path')).toEqual([['/Team folder/Reports']])
	})

	it('shows folder names with special characters as they are, not HTML-escaped', async () => {
		pickNodesMock.mockRejectedValue(new FilePickerClosedMock())
		const wrapper = mount(TargetFolderPicker, { props: { path: '/R&D <2026>' } })

		expect(wrapper.find('.target-folder-picker__path').text()).toBe('R&D <2026>')
		expect(wrapper.findComponent({ name: 'NcButton' }).attributes('aria-label'))
			.toBe('Change folder, currently R&D <2026>')

		await clickChange(wrapper)

		const [button] = builder.buttonFactory!([folder('/R&D <2026>', Permission.ALL)])
		expect(button.label).toBe('Choose R&D <2026>')
	})

	it('announces a changed folder to screen readers', () => {
		const wrapper = mount(TargetFolderPicker, { props: { path: '/Documents' } })

		expect(wrapper.find('.target-folder-picker__path').attributes('aria-live')).toBe('polite')
	})

	it('disables the change button while disabled', () => {
		const wrapper = mount(TargetFolderPicker, { props: { path: '/', disabled: true } })

		expect(wrapper.findComponent({ name: 'NcButton' }).props('disabled')).toBe(true)
	})

	it('reports a picker failure other than closing, instead of throwing', async () => {
		pickNodesMock.mockRejectedValue(new Error('Dialog was unmounted without close event'))
		const wrapper = mount(TargetFolderPicker, { props: { path: '/' } })

		await clickChange(wrapper)

		expect(wrapper.emitted('error')).toHaveLength(1)
		expect(wrapper.emitted('update:path')).toBeUndefined()
	})

	it('keeps the current folder when the picker is closed without choosing', async () => {
		pickNodesMock.mockRejectedValue(new FilePickerClosedMock())
		const wrapper = mount(TargetFolderPicker, { props: { path: '/Documents' } })

		await clickChange(wrapper)

		expect(wrapper.emitted('update:path')).toBeUndefined()
		expect(wrapper.emitted('error')).toBeUndefined()
	})
})
