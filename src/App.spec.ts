import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from './App.vue'

// App.vue's only child is the routed view, which fetches on setup — a
// placeholder route component keeps that out of this spec. Which view a URL
// resolves to is covered by router.spec.ts.
vi.mock('./services/templates.ts', () => ({
	getTemplates: vi.fn().mockResolvedValue([]),
	createFromTemplate: vi.fn(),
}))

describe('App', () => {
	it('renders the routed view, so the URL decides what is shown', async () => {
		// Memory history rather than router.ts's own web-history instance: jsdom
		// shares one real window.history across tests.
		const router = createRouter({
			history: createMemoryHistory(),
			routes: [{ name: 'creator', path: '/:creatorId?', component: { template: '<div />' } }],
		})
		router.push('/')
		await router.isReady()

		const wrapper = shallowMount(App, { global: { plugins: [router] } })

		expect(wrapper.findComponent({ name: 'RouterView' }).exists()).toBe(true)

		// shallowMount stubs vnodes process-wide until the wrapper is unmounted;
		// leaving it mounted would stub every later render in this run.
		wrapper.unmount()
	})
})
