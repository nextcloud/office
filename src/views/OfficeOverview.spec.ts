import type { Node } from '@nextcloud/files'

import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { makeCreator, makeNode } from '../test-utils/fixtures.ts'

const getTemplatesMock = vi.fn()
const createFromTemplateMock = vi.fn()
const getAllOfficeFilesMock = vi.fn()
const invalidateOfficeFilesCacheMock = vi.fn()

vi.mock('../services/templates.ts', () => ({
	getTemplates: getTemplatesMock,
	createFromTemplate: createFromTemplateMock,
}))

vi.mock('../services/officeFiles.ts', async (importOriginal) => {
	const actual = await importOriginal<typeof import('../services/officeFiles.ts')>()
	return {
		...actual,
		getAllOfficeFiles: getAllOfficeFilesMock,
		invalidateOfficeFilesCache: invalidateOfficeFilesCacheMock,
	}
})

// shallowMount's auto-stubs only render the default slot (see
// config.global.renderStubDefaultSlot in vitest.setup.ts), never named slots
// — but this template relies on named slots throughout (NcAppNavigation's
// #search, NcEmptyContent's #description, NcDialog's #actions). Un-stubbing
// the real components instead cascades into their own internal children also
// needing to be real (e.g. NcDialog -> NcModal -> NcInputField, which crashes
// NcTextField's exposed focus() against a stubbed grandchild — a test-harness
// artifact, not a real bug). A generic stub that renders every slot it
// receives sidesteps both problems.
// name must match the real component's name — findComponent({ name }) below
// matches against it, and a nameless replacement component won't match.
function stubRenderingAllSlots(name: string, props: string[] = []) {
	return {
		name,
		props,
		template: '<div><slot v-for="(_, name) in $slots" :key="name" :name="name" /></div>',
	}
}

// NcDialog additionally needs to respect `open`, or content would always render.
const NC_DIALOG_STUB = {
	name: 'NcDialog',
	props: ['open', 'name'],
	template: '<div v-if="open"><slot /><slot name="actions" /></div>',
}

// Module-level side effects (getCurrentUser()?.uid, fetchAll(), loadState()) run
// at import time, so mocks must be configured before each fresh dynamic import.
// vi.resetModules() + a fresh dynamic import are required per test to avoid
// officeFiles.ts's cachedResult singleton and the one-time fetchAll() leaking
// state across tests — and because resetModules gives the freshly re-imported
// OfficeOverview.vue its own instances of every module it imports, component
// lookups below always match by { name } rather than by imported reference:
// an object-identity match (e.g. findComponent(NcButton)) silently fails
// because "our" NcButton import and OfficeOverview's are different instances.
// extraStubs merges in on top of the defaults below — used by tests that need
// a named slot rendered on a component that's normally left as a plain
// shallow stub (e.g. NcListItem's #icon, FileCard's #preview).
async function mountOverview(extraStubs: Record<string, unknown> = {}) {
	vi.resetModules()
	const { default: OfficeOverview } = await import('./OfficeOverview.vue')
	const wrapper = shallowMount(OfficeOverview, {
		global: {
			stubs: {
				NcDialog: NC_DIALOG_STUB,
				NcAppNavigation: stubRenderingAllSlots('NcAppNavigation'),
				NcEmptyContent: stubRenderingAllSlots('NcEmptyContent', ['name']),
				...extraStubs,
			},
		},
	})
	await flushPromises()
	return wrapper
}

// getAllOfficeFiles now resolves { nodes, truncated } rather than a bare array —
// this wraps a node list in that shape so existing mocks don't have to repeat it.
function officeFilesResult(nodes: Node[], truncated = false) {
	return { nodes, truncated }
}

function findButtonByText(wrapper: Awaited<ReturnType<typeof mountOverview>>, text: string) {
	const button = wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === text)
	if (!button) { throw new Error(`No NcButton with text "${text}" found`) }
	return button
}

beforeEach(() => {
	vi.mocked(getCurrentUser).mockReturnValue({ uid: 'alice' } as ReturnType<typeof getCurrentUser>)
	vi.mocked(loadState).mockReturnValue(null)
	getTemplatesMock.mockReset()
	createFromTemplateMock.mockReset()
	getAllOfficeFilesMock.mockReset()
	invalidateOfficeFilesCacheMock.mockReset()
	localStorage.clear()

	// jsdom's real Location.href setter attempts actual navigation, logs
	// "Not implemented: navigation to another Document", and does not persist
	// the assigned value — reading window.location.href back afterwards
	// always shows the original URL. Its own property is also non-configurable
	// (can't vi.spyOn the setter). Replacing window.location itself (the
	// property on `window`, not a property of the existing Location instance)
	// is permitted, unlike patching the instance in place. _oc_webroot short-
	// circuits @nextcloud/router's getRootUrl() so it never needs
	// location.pathname; protocol/host cover getBaseUrl()'s other read.
	;(window as unknown as { _oc_webroot: string })._oc_webroot = ''
	Object.defineProperty(window, 'location', {
		writable: true,
		configurable: true,
		value: { protocol: 'http:', host: 'localhost:3000', href: '' },
	})
})

describe('OfficeOverview > rendering states', () => {
	it('shows the loading spinner while fetchAll is in flight', async () => {
		getTemplatesMock.mockReturnValue(new Promise(() => {})) // never resolves
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([]))

		vi.resetModules()
		const { default: OfficeOverview } = await import('./OfficeOverview.vue')
		const wrapper = shallowMount(OfficeOverview)

		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(false)
	})

	it('shows "No office suite installed" when there are zero creators', async () => {
		getTemplatesMock.mockResolvedValue([])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([]))

		const wrapper = await mountOverview()

		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No office suite installed')
	})

	it('shows the error state when getAllOfficeFiles fails (after creators loaded)', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockRejectedValue(new Error('network error'))

		const wrapper = await mountOverview()

		const emptyContents = wrapper.findAllComponents({ name: 'NcEmptyContent' })
		expect(emptyContents.some((c) => c.props('name') === 'Failed to load files')).toBe(true)
	})

	// Not a test bug: this is what the component actually does. fetchAll()'s
	// catch only sets the "Failed to load files" error state if it's reached
	// — but creators.value is only ever assigned *after* getTemplates()
	// resolves, so a getTemplates() rejection leaves creators empty and the
	// template shows "No office suite installed" instead, before the error
	// branch is ever reached. A misleading message for a network failure,
	// but out of scope for a behaviour-preserving refactor — characterizing
	// it, not fixing it.
	it('shows "No office suite installed" (not the error state) when getTemplates itself fails', async () => {
		getTemplatesMock.mockRejectedValue(new Error('network error'))

		const wrapper = await mountOverview()

		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No office suite installed')
	})

	it('shows "No {category} found" with a switch-to-All hint when the mine filter has no matches', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([])) // nothing matches "mine" (the default filter)

		const wrapper = await mountOverview()

		const noFilesFound = wrapper.findAllComponents({ name: 'NcEmptyContent' }).find((c) => c.props('name') === 'No Documents found')
		expect(noFilesFound).toBeTruthy()
		expect(noFilesFound!.text()).toContain('Switch to "All" to see every file you have access to')
	})

	it('does not show the switch-to-All hint when the active filter is already "all"', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([]))

		const wrapper = await mountOverview()
		await findButtonByText(wrapper, 'All').vm.$emit('click')
		await flushPromises()

		const noFilesFound = wrapper.findAllComponents({ name: 'NcEmptyContent' }).find((c) => c.props('name') === 'No Documents found')
		expect(noFilesFound!.text()).not.toContain('Switch to "All"')
	})

	it('renders files in list view by default (grid view not persisted)', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([makeNode({ owner: 'alice', basename: 'report.odt' })]))

		const wrapper = await mountOverview()

		expect(wrapper.findComponent({ name: 'NcListItem' }).props('name')).toBe('report.odt')
		expect(wrapper.findComponent({ name: 'FileCard' }).exists()).toBe(false)
	})

	it('renders files in grid view when persisted via localStorage', async () => {
		localStorage.setItem('office.overview.gridView', 'true')
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([makeNode({ owner: 'alice', basename: 'report.odt' })]))

		const wrapper = await mountOverview()

		expect(wrapper.findComponent({ name: 'FileCard' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcListItem' }).exists()).toBe(false)
	})
})

describe('OfficeOverview > preview thumbnails', () => {
	// FilePreview's own rendering (image src, error-to-icon fallback) is
	// covered by FilePreview.spec.ts in isolation. These tests are wiring
	// only: does each view pass FilePreview the props it's supposed to?
	// NcListItem/FileCard's default shallow stub only renders the default
	// slot (see stubRenderingAllSlots' comment above), so their #icon/#preview
	// named slots — where FilePreview lives — need it rendered explicitly.
	const LIST_ITEM_STUB = stubRenderingAllSlots('NcListItem', ['name', 'active'])
	const FILE_CARD_STUB = stubRenderingAllSlots('FileCard', [])

	it('passes list view a small thumbnail size and the file, decorative (no alt)', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		const file = makeNode({ owner: 'alice', basename: 'report.odt' })
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([file]))

		const wrapper = await mountOverview({ NcListItem: LIST_ITEM_STUB })

		const preview = wrapper.findComponent({ name: 'FilePreview' })
		// Vue wraps allFiles.value in a reactive proxy, so the prop is a proxied
		// copy, not the exact same reference as `file` — compare by fileid.
		expect(preview.props('file').fileid).toBe(file.fileid)
		expect(preview.props('size')).toBe(96)
		expect(preview.props('fallbackIconSize')).toBe(32)
		expect(preview.props('alt')).toBeFalsy()
		expect(preview.classes()).toContain('office-overview__list-thumb')
	})

	it('passes grid view the file\'s basename as alt text (not decorative)', async () => {
		localStorage.setItem('office.overview.gridView', 'true')
		getTemplatesMock.mockResolvedValue([makeCreator()])
		const file = makeNode({ owner: 'alice', basename: 'report.odt' })
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([file]))

		const wrapper = await mountOverview({ FileCard: FILE_CARD_STUB })

		const preview = wrapper.findComponent({ name: 'FilePreview' })
		// Vue wraps allFiles.value in a reactive proxy, so the prop is a proxied
		// copy, not the exact same reference as `file` — compare by fileid.
		expect(preview.props('file').fileid).toBe(file.fileid)
		expect(preview.props('alt')).toBe('report.odt')
	})
})

describe('OfficeOverview > openFile', () => {
	it('navigates to the WOPI editor URL with fileId when editorUrl is set', async () => {
		vi.mocked(loadState).mockReturnValue('/apps/office/editor')
		getTemplatesMock.mockResolvedValue([makeCreator()])
		const file = makeNode({ owner: 'alice' })
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([file]))

		const wrapper = await mountOverview()
		await wrapper.findComponent({ name: 'NcListItem' }).vm.$emit('click', new MouseEvent('click'))

		expect(window.location.href).toBe(`/apps/office/editor?fileId=${file.fileid}`)
	})

	it('navigates to /f/{fileid} when no WOPI editor is configured', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		const file = makeNode({ owner: 'alice' })
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([file]))

		const wrapper = await mountOverview()
		await wrapper.findComponent({ name: 'NcListItem' }).vm.$emit('click', new MouseEvent('click'))

		expect(window.location.href).toBe(`/f/${file.fileid}`)
	})
})

describe('OfficeOverview > openInFiles', () => {
	// MAX_DISPLAY_FILES is 200; need >200 matching files so hasMoreFiles is true
	// and the "Show/Search all in Files" button actually renders. "report" in
	// every basename so a search for it doesn't filter the count back below
	// the cap (this describe block isn't testing search filtering itself —
	// that's fileFilters.spec.ts's job — just openInFiles' URL construction).
	function manyFiles(count: number) {
		return Array.from({ length: count }, (_, i) => makeNode({ owner: 'alice', basename: `report-${i}.odt` }))
	}

	it('navigates to the recent-files URL when there is no search query', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult(manyFiles(201)))

		const wrapper = await mountOverview()
		await findButtonByText(wrapper, 'Show all in Files').vm.$emit('click')

		expect(window.location.href).toBe('/apps/files/recent')
	})

	it('navigates to the files search URL with the query when a search is active', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult(manyFiles(201)))

		const wrapper = await mountOverview()
		await wrapper.findComponent({ name: 'NcAppNavigationSearch' }).vm.$emit('update:modelValue', 'report')
		await flushPromises()
		await findButtonByText(wrapper, 'Search all in Files').vm.$emit('click')

		expect(window.location.href).toBe('/apps/files/search?query=report')
	})
})

describe('OfficeOverview > MAX_DISPLAY_FILES cap', () => {
	// Regression test for the newest-first fix: filterFiles() sorts before
	// OfficeOverview slices to MAX_DISPLAY_FILES (200), so which 200 survive
	// the cap depends entirely on sort direction. Distinct, strictly
	// increasing mtimes (unlike openInFiles' manyFiles() above, which only
	// needs a count) let this test tell newest from oldest.
	function filesWithIncreasingMtime(count: number) {
		return Array.from({ length: count }, (_, i) => makeNode({
			owner: 'alice',
			basename: `report-${i}.odt`,
			mtime: new Date(2024, 0, 1 + i),
		}))
	}

	it('keeps the newest files, not the oldest, when more than MAX_DISPLAY_FILES match', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult(filesWithIncreasingMtime(201)))

		const wrapper = await mountOverview()

		const renderedNames = wrapper.findAllComponents({ name: 'NcListItem' }).map((item) => item.props('name'))
		expect(renderedNames).toHaveLength(200)
		expect(renderedNames).toContain('report-200.odt') // newest (highest index/mtime)
		expect(renderedNames).not.toContain('report-0.odt') // oldest, pushed out by the cap
	})
})

describe('OfficeOverview > truncated server results', () => {
	// Regression test for the DAV SEARCH orderby/limit fix: a truncated server
	// response means office files older than what's shown exist but were never
	// fetched, which is exactly the "there's more" case "Show all in Files" is
	// for — even when the fetched set is well under MAX_DISPLAY_FILES.
	it('shows "Show all in Files" when the server search was truncated, even under MAX_DISPLAY_FILES', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([makeNode({ owner: 'alice' })], true))

		const wrapper = await mountOverview()

		expect(() => findButtonByText(wrapper, 'Show all in Files')).not.toThrow()
	})

	it('does not show "Show all in Files" when the result is small and not truncated', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([makeNode({ owner: 'alice' })], false))

		const wrapper = await mountOverview()

		expect(() => findButtonByText(wrapper, 'Show all in Files')).toThrow()
	})

	// Characterizing existing behaviour, not a bug: `hasMoreFiles` is `|| truncated`,
	// and `truncated` describes the shared search across all categories, not this
	// category specifically — so "No {category} found" and "Show all in Files" can
	// render together. Before this PR that combination was impossible (hasMoreFiles
	// was purely a count on the already-empty filtered list), but a sparse category
	// is exactly the one most likely to have real matches sitting past a global
	// cutoff, so surfacing the escape hatch here is the conservative-correct call,
	// not a contradiction to hide.
	it('shows both the empty-category state and "Show all in Files" when the category has no matches but the search was truncated', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()]) // default category mime: .odt text
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult(
			[makeNode({ owner: 'alice', mime: 'application/vnd.oasis.opendocument.spreadsheet' })], // different category
			true,
		))

		const wrapper = await mountOverview()

		const noFilesFound = wrapper.findAllComponents({ name: 'NcEmptyContent' }).find((c) => c.props('name') === 'No Documents found')
		expect(noFilesFound).toBeTruthy()
		expect(() => findButtonByText(wrapper, 'Show all in Files')).not.toThrow()
	})
})

describe('OfficeOverview > toggleViewMode', () => {
	it('flips list/grid and persists the new mode', async () => {
		getTemplatesMock.mockResolvedValue([makeCreator()])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([makeNode({ owner: 'alice' })]))

		const wrapper = await mountOverview()
		expect(localStorage.getItem('office.overview.gridView')).toBeNull()

		const toggle = wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.props('variant') === 'tertiary' && b.text() === '')
		if (!toggle) { throw new Error('view-toggle button not found') }
		await toggle.vm.$emit('click')

		expect(localStorage.getItem('office.overview.gridView')).toBe('true')
		expect(wrapper.findComponent({ name: 'FileCard' }).exists()).toBe(true)
	})
})

describe('OfficeOverview > doCreateFromTemplate', () => {
	async function mountWithDialogOpen() {
		const creator = makeCreator()
		getTemplatesMock.mockResolvedValue([creator])
		getAllOfficeFilesMock.mockResolvedValue(officeFilesResult([]))
		const wrapper = await mountOverview()

		await wrapper.findComponent({ name: 'TemplateSection' }).vm.$emit('select', creator, null)
		await flushPromises()

		return wrapper
	}

	async function setFilename(wrapper: Awaited<ReturnType<typeof mountWithDialogOpen>>, name: string) {
		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', name)
		await flushPromises()
	}

	it('surfaces validation errors without calling the service', async () => {
		const wrapper = await mountWithDialogOpen()
		await setFilename(wrapper, '   ')

		await findButtonByText(wrapper, 'Create').vm.$emit('click')
		await flushPromises()

		expect(createFromTemplateMock).not.toHaveBeenCalled()
		expect(wrapper.findComponent({ name: 'NcTextField' }).props('helperText')).toBe('Filename cannot be empty')
	})

	it('creates the file, closes the dialog, invalidates the cache and navigates on success', async () => {
		const wrapper = await mountWithDialogOpen()
		createFromTemplateMock.mockResolvedValue({ fileid: 99, basename: 'new.odt', filename: '/new.odt' })
		await setFilename(wrapper, 'new.odt')

		await findButtonByText(wrapper, 'Create').vm.$emit('click')
		await flushPromises()

		expect(createFromTemplateMock).toHaveBeenCalledWith('/new.odt', '', 'user_system')
		expect(invalidateOfficeFilesCacheMock).toHaveBeenCalled()
		expect(window.location.href).toBe('/f/99')
	})

	it('surfaces the OCS error message on failure', async () => {
		const wrapper = await mountWithDialogOpen()
		createFromTemplateMock.mockRejectedValue({ response: { data: { ocs: { meta: { message: 'Quota exceeded' } } } } })
		await setFilename(wrapper, 'new.odt')

		await findButtonByText(wrapper, 'Create').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcTextField' }).props('helperText')).toBe('Quota exceeded')
	})

	it('falls back to a generic error message when the OCS response has none', async () => {
		const wrapper = await mountWithDialogOpen()
		createFromTemplateMock.mockRejectedValue(new Error('network down'))
		await setFilename(wrapper, 'new.odt')

		await findButtonByText(wrapper, 'Create').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcTextField' }).props('helperText')).toBe('Failed to create file')
	})
})
