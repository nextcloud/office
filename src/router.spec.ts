import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { loadState } from '@nextcloud/initial-state'
import { createMemoryHistory, createRouter } from 'vue-router'
import { fakeLoadState, makeCreator } from './test-utils/fixtures.ts'
import { historyBase, routes } from './router.ts'
import OfficeOverview from './views/OfficeOverview.vue'
import type { VueWrapper } from '@vue/test-utils'

// The two creators the overview gets from the templates API in this spec; their
// categories come from the initial state (see fakeLoadState).
const CREATORS = [
	makeCreator({ extension: '.odt' }),
	makeCreator({
		label: 'Spreadsheet',
		extension: '.ods',
		mimetypes: ['application/vnd.oasis.opendocument.spreadsheet'],
	}),
]

// OfficeOverview.vue fetches on setup — stub its two network-touching services
// so importing the route table doesn't fetch.
vi.mock('./services/templates.ts', () => ({
	getTemplates: vi.fn(() => Promise.resolve(CREATORS)),
	createFromTemplate: vi.fn(),
}))
vi.mock('./services/officeFiles.ts', async (importOriginal) => {
	const actual = await importOriginal<typeof import('./services/officeFiles.ts')>()
	return {
		...actual,
		getAllOfficeFiles: vi.fn(() => Promise.resolve({ nodes: [], truncated: false })),
		invalidateOfficeFilesCache: vi.fn(),
	}
})

beforeEach(() => {
	vi.mocked(loadState).mockImplementation(fakeLoadState())
})

// The exported route table, matched through a memory history: the real router's
// web history is tied to the app's base URL, which doesn't exist under jsdom.
const router = createRouter({ history: createMemoryHistory(), routes })

describe('routes', () => {
	it('serves the overview at the app root, with no creator id', () => {
		const resolved = router.resolve('/')

		expect(resolved.matched[0].components?.default).toBe(OfficeOverview)
		expect(resolved.params.creatorId).toBeUndefined()
	})

	it('serves the overview for a creator URL and hands it the id', () => {
		const resolved = router.resolve('/spreadsheets')

		expect(resolved.matched[0].components?.default).toBe(OfficeOverview)
		expect(resolved.params.creatorId).toBe('spreadsheets')
	})

	it('builds a creator URL from the id', () => {
		expect(router.resolve({ name: 'creator', params: { creatorId: 'diagrams' } }).path)
			.toBe('/diagrams')
	})

	// PageController's catch-all serves the shell for any path under the app, so
	// an unmatched one must not leave the view unmounted.
	it('sends a path below the creator level back to the overview', () => {
		const resolved = router.resolve('/documents/extra')

		expect(resolved.matched[0].redirect).toBe('/')
	})

	it('tolerates a trailing slash on a creator URL', () => {
		const resolved = router.resolve('/documents/')

		expect(resolved.matched[0].components?.default).toBe(OfficeOverview)
		expect(resolved.params.creatorId).toBe('documents')
	})
})

// Regression: with a base in the form the current URL does not use, the base is
// never stripped, so a creator URL matches no route and the fallback swallows it
// — the creator on the URL is then silently ignored.
describe('historyBase', () => {
	it('is the app path of a rewritten URL', () => {
		expect(historyBase('/apps/office/spreadsheets')).toBe('/apps/office')
	})

	it('keeps the index.php prefix of a non-rewritten URL', () => {
		expect(historyBase('/index.php/apps/office/spreadsheets')).toBe('/index.php/apps/office')
	})

	it('keeps the webroot of a subdirectory install', () => {
		expect(historyBase('/nextcloud/index.php/apps/office/diagrams')).toBe('/nextcloud/index.php/apps/office')
	})

	it('is the app path itself when the URL carries no creator', () => {
		expect(historyBase('/apps/office')).toBe('/apps/office')
	})

	// generateUrl() is mocked to return the path unchanged (see vitest.setup.ts).
	it('falls back to the generated URL for a path outside the app', () => {
		expect(historyBase('/some/other/place')).toBe('/apps/office')
	})
})

// The router the app really runs on: web history, a base taken from the URL the
// page was loaded from, and an initial navigation that resolves after the mount.
// The specs elsewhere use a memory history and mount the view directly, which
// skips both — and that is where the creator on the URL went missing.
describe('booting at a creator URL', () => {
	let wrapper: VueWrapper | null = null

	// The app's shell, stubbed to render its slots: the view's own markup (the
	// category heading) is what these assert on, and the real components bring
	// teleports and observers that only misbehave under jsdom.
	function stubRenderingAllSlots(name: string) {
		return {
			name,
			template: '<div><slot v-for="(_, slot) in $slots" :key="slot" :name="slot" /></div>',
		}
	}

	async function bootAt(url: string) {
		window.history.replaceState({}, '', url)
		// router.ts reads the URL when it creates the router, so it can only be
		// imported once the URL is in place.
		vi.resetModules()
		const { router } = await import('./router.ts')
		const { default: App } = await import('./App.vue')

		wrapper = mount(App, {
			global: {
				plugins: [router],
				stubs: {
					NcContent: stubRenderingAllSlots('NcContent'),
					NcAppNavigation: stubRenderingAllSlots('NcAppNavigation'),
					NcAppContent: stubRenderingAllSlots('NcAppContent'),
				},
			},
		})
		// Only mounting installs the router, and only that starts the first
		// navigation — so this has to come after mount(), not before it.
		await router.isReady()
		// Two rounds: the first renders the view once the navigation resolved, the
		// second lets its creator request settle so the heading is there.
		await flushPromises()
		await flushPromises()
		return { wrapper, router }
	}

	afterEach(() => {
		// Also resets @vue/test-utils' process-wide vnode stubbing.
		wrapper?.unmount()
		wrapper = null
		window.history.replaceState({}, '', '/')
	})

	it('opens the category the URL names', async () => {
		const { wrapper, router } = await bootAt('/apps/office/spreadsheets')

		expect(router.currentRoute.value.params.creatorId).toBe('spreadsheets')
		expect(wrapper.text()).toContain('Recent Spreadsheets')
	})

	// generateUrl() only returns the form the instance advertises, so the base has
	// to follow the URL in use, not the other way round.
	it('opens the category the URL names behind an index.php front controller', async () => {
		const { wrapper, router } = await bootAt('/index.php/apps/office/spreadsheets')

		expect(router.currentRoute.value.params.creatorId).toBe('spreadsheets')
		expect(wrapper.text()).toContain('Recent Spreadsheets')
	})

	it('falls back to the first creator at the app root, naming it on the URL', async () => {
		const { wrapper, router } = await bootAt('/apps/office')

		expect(wrapper.text()).toContain('Recent Documents')
		expect(router.currentRoute.value.params.creatorId).toBe('documents')
	})
})
