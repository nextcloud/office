import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FileCard from './FileCard.vue'

describe('FileCard', () => {
	it('emits click with the MouseEvent when the button is clicked', async () => {
		const wrapper = mount(FileCard)

		await wrapper.find('button').trigger('click')

		expect(wrapper.emitted('click')).toHaveLength(1)
		expect(wrapper.emitted('click')![0][0]).toBeInstanceOf(MouseEvent)
	})

	it('always renders the preview and name slots', () => {
		const wrapper = mount(FileCard, {
			slots: { preview: 'a preview', name: 'a name' },
		})

		expect(wrapper.find('.file-card__preview').text()).toBe('a preview')
		expect(wrapper.find('.file-card__name').text()).toBe('a name')
	})

	it('only renders the icon slot when provided', () => {
		const without = mount(FileCard)
		expect(without.find('.file-card__icon').exists()).toBe(false)

		const withIcon = mount(FileCard, { slots: { icon: 'an icon' } })
		expect(withIcon.find('.file-card__icon').text()).toBe('an icon')
	})

	it('only renders the subname slot when provided', () => {
		const without = mount(FileCard)
		expect(without.find('.file-card__subname').exists()).toBe(false)

		const withSubname = mount(FileCard, { slots: { subname: 'a subname' } })
		expect(withSubname.find('.file-card__subname').text()).toBe('a subname')
	})

	it('only renders the overlay slot when provided', () => {
		const without = mount(FileCard)
		expect(without.find('.file-card__overlay').exists()).toBe(false)

		const withOverlay = mount(FileCard, { slots: { overlay: 'a badge' } })
		expect(withOverlay.find('.file-card__overlay').text()).toBe('a badge')
	})
})
