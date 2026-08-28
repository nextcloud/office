import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TemplateSection from './TemplateSection.vue'
import type { TemplateCreator, TemplateFile } from '../services/templates.ts'

function makeTemplate(overrides: Partial<TemplateFile> = {}): TemplateFile {
	return {
		fileid: 1,
		basename: 'Report.odt',
		filename: '/Report.odt',
		templateId: 'tpl-1',
		templateType: 'user_system',
		hasPreview: false,
		...overrides,
	}
}

function makeCreator(overrides: Partial<TemplateCreator> = {}): TemplateCreator {
	return {
		app: 'richdocuments',
		label: 'Document',
		extension: '.odt',
		mimetypes: ['application/vnd.oasis.opendocument.text'],
		templates: [],
		...overrides,
	}
}

beforeEach(() => {
	// jsdom doesn't implement scrollBy, and reports scroll metrics as 0 —
	// both need explicit control for updateArrows()/scrollByStep() to be
	// meaningfully testable (see vitest.setup.ts / the plan for why).
	Element.prototype.scrollBy = vi.fn()
})

describe('TemplateSection > items', () => {
	it('always prepends the blank card before the creator templates', () => {
		const creator = makeCreator({ templates: [makeTemplate({ fileid: 1, basename: 'A.odt' })] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		const names = wrapper.findAll('.template-card__name').map(n => n.text())
		expect(names).toEqual(['Blank', 'A'])
	})
})

describe('TemplateSection > nameWithoutExt', () => {
	it('strips the extension from an ordinary filename', () => {
		const creator = makeCreator({ templates: [makeTemplate({ basename: 'Report.odt' })] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.findAll('.template-card__name')[1].text()).toBe('Report')
	})

	it('keeps a dotfile name whole (dot at index 0 does not count)', () => {
		const creator = makeCreator({ templates: [makeTemplate({ basename: '.gitignore' })] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.findAll('.template-card__name')[1].text()).toBe('.gitignore')
	})

	it('keeps a name with no extension whole', () => {
		const creator = makeCreator({ templates: [makeTemplate({ basename: 'README' })] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.findAll('.template-card__name')[1].text()).toBe('README')
	})
})

describe('TemplateSection > click wiring', () => {
	it('emits select with a null template for the blank card', async () => {
		const creator = makeCreator()
		const wrapper = mount(TemplateSection, { props: { creator } })

		await wrapper.find('.template-card').trigger('click')

		expect(wrapper.emitted('select')).toEqual([[creator, null]])
	})

	it('emits select with the template for a template card', async () => {
		const template = makeTemplate()
		const creator = makeCreator({ templates: [template] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		await wrapper.findAll('.template-card')[1].trigger('click')

		expect(wrapper.emitted('select')).toEqual([[creator, template]])
	})
})

describe('TemplateSection > template preview', () => {
	it('uses previewUrl as-is when present', () => {
		const template = makeTemplate({ hasPreview: true, previewUrl: 'https://example.com/preview.png' })
		const creator = makeCreator({ templates: [template] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.find('.template-card__image').attributes('src')).toBe('https://example.com/preview.png')
	})

	it('falls back to generateUrl when previewUrl is absent', () => {
		const template = makeTemplate({ hasPreview: true, fileid: 42 })
		const creator = makeCreator({ templates: [template] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.find('.template-card__image').attributes('src'))
			.toBe('/core/preview?fileId=42&x=256&y=256&a=1')
	})

	it('falls back to the icon when the preview image fails to load', async () => {
		const template = makeTemplate({ hasPreview: true })
		const creator = makeCreator({ templates: [template] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.find('.template-card__image').exists()).toBe(true)
		await wrapper.find('.template-card__image').trigger('error')

		expect(wrapper.find('.template-card__image').exists()).toBe(false)
		expect(wrapper.findAllComponents({ name: 'NcIconSvgWrapper' }).length).toBeGreaterThan(0)
	})
})

describe('TemplateSection > themeType', () => {
	it('maps a known mime to its theme and uses the matching palette', () => {
		const creator = makeCreator({ mimetypes: ['application/vnd.ms-powerpoint'] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		// presentation palette's first glow colour, from THEME_PALETTES.
		expect(wrapper.find('.template-section').attributes('style')).toContain('hsl(23 83% 80%)')
	})

	it('falls back to the document theme for an unmapped mime', () => {
		const creator = makeCreator({ mimetypes: ['application/x-unknown'] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.find('.template-section').attributes('style')).toContain('hsl(203 79% 78%)')
	})

	it('falls back to the document theme when mimetypes is empty', () => {
		const creator = makeCreator({ mimetypes: [] })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.find('.template-section').attributes('style')).toContain('hsl(203 79% 78%)')
	})
})

describe('TemplateSection > accessibility', () => {
	it('labels the scrollable list with the creator name', () => {
		const creator = makeCreator({ label: 'Spreadsheet' })
		const wrapper = mount(TemplateSection, { props: { creator } })

		expect(wrapper.find('.template-section__list').attributes('aria-label'))
			.toBe('Spreadsheet templates, scrollable')
	})
})

describe('TemplateSection > scroll arrows', () => {
	function setScrollMetrics(el: HTMLElement, { scrollLeft = 0, scrollWidth = 0, clientWidth = 0 }) {
		Object.defineProperty(el, 'scrollLeft', { value: scrollLeft, writable: true, configurable: true })
		Object.defineProperty(el, 'scrollWidth', { value: scrollWidth, configurable: true })
		Object.defineProperty(el, 'clientWidth', { value: clientWidth, configurable: true })
	}

	// useScrollArrows attaches its scroll listener from a watcher that only
	// runs once Vue flushes post-render effects — mount() itself doesn't wait
	// for that, so anything dispatched immediately after would be missed.
	async function mountAndSettle(creator: TemplateCreator) {
		const wrapper = mount(TemplateSection, { props: { creator } })
		await nextTick()
		return wrapper
	}

	it('hides the nav entirely when the list does not overflow', async () => {
		const creator = makeCreator()
		const wrapper = await mountAndSettle(creator)
		const list = wrapper.find('.template-section__list').element as HTMLElement
		setScrollMetrics(list, { scrollLeft: 0, scrollWidth: 100, clientWidth: 100 })
		await wrapper.find('.template-section__list').trigger('scroll')

		expect(wrapper.find('.template-section__nav').exists()).toBe(false)
	})

	it('enables the right arrow (not left) when scrolled to the start of an overflowing list', async () => {
		const creator = makeCreator()
		const wrapper = await mountAndSettle(creator)
		const list = wrapper.find('.template-section__list').element as HTMLElement
		setScrollMetrics(list, { scrollLeft: 0, scrollWidth: 500, clientWidth: 200 })
		await wrapper.find('.template-section__list').trigger('scroll')

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		expect(buttons[0].props('disabled')).toBe(true) // left
		expect(buttons[1].props('disabled')).toBe(false) // right
	})

	it('enables the left arrow (not right) when scrolled to the end of an overflowing list', async () => {
		const creator = makeCreator()
		const wrapper = await mountAndSettle(creator)
		const list = wrapper.find('.template-section__list').element as HTMLElement
		setScrollMetrics(list, { scrollLeft: 300, scrollWidth: 500, clientWidth: 200 })
		await wrapper.find('.template-section__list').trigger('scroll')

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		expect(buttons[0].props('disabled')).toBe(false) // left
		expect(buttons[1].props('disabled')).toBe(true) // right
	})

	it('scrolls right by max(card+gap, clientWidth - (card+gap))', async () => {
		const creator = makeCreator()
		const wrapper = await mountAndSettle(creator)
		const list = wrapper.find('.template-section__list').element as HTMLElement
		// CARD_WIDTH=160, CARD_GAP=12: card+gap=172, clientWidth-172=28 -> max is 172.
		setScrollMetrics(list, { scrollLeft: 0, scrollWidth: 500, clientWidth: 200 })
		await wrapper.find('.template-section__list').trigger('scroll')

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		await buttons[1].vm.$emit('click')

		expect(list.scrollBy).toHaveBeenCalledWith({ left: 172, behavior: 'smooth' })
	})

	it('scrolls left as a negative step when the left arrow is clicked', async () => {
		const creator = makeCreator()
		const wrapper = await mountAndSettle(creator)
		const list = wrapper.find('.template-section__list').element as HTMLElement
		setScrollMetrics(list, { scrollLeft: 300, scrollWidth: 500, clientWidth: 200 })
		await wrapper.find('.template-section__list').trigger('scroll')

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		await buttons[0].vm.$emit('click')

		expect(list.scrollBy).toHaveBeenCalledWith({ left: -172, behavior: 'smooth' })
	})
})
