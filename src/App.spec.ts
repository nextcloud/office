import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import App from './App.vue'

// App.vue's only child, OfficeOverview.vue, runs fetchAll() at module
// evaluation time — stub its two network-touching services so mounting
// App doesn't attempt a real fetch.
vi.mock('./services/templates.ts', () => ({
	getTemplates: vi.fn().mockResolvedValue([]),
	createFromTemplate: vi.fn(),
}))
vi.mock('./services/officeFiles.ts', async (importOriginal) => {
	const actual = await importOriginal<typeof import('./services/officeFiles.ts')>()
	return {
		...actual,
		getAllOfficeFiles: vi.fn().mockResolvedValue({ nodes: [], truncated: false }),
		invalidateOfficeFilesCache: vi.fn(),
	}
})

describe('App', () => {
	it('renders OfficeOverview', () => {
		const wrapper = shallowMount(App)

		expect(wrapper.findComponent({ name: 'OfficeOverview' }).exists()).toBe(true)
	})
})
