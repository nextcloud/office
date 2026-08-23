<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	mdiFileDocumentOutline,
	mdiOpenInNew,
	mdiStar,
	mdiViewGrid,
	mdiViewList,
} from '@mdi/js'
import FileCard from '../components/FileCard.vue'
import FilePreview from '../components/FilePreview.vue'
import ShareIndicator from '../components/ShareIndicator.vue'
import TemplateSection from '../components/TemplateSection.vue'
import { getAllOfficeFiles, invalidateOfficeFilesCache, MAX_DISPLAY_FILES } from '../services/officeFiles.ts'
import { getTemplates, createFromTemplate } from '../services/templates.ts'
import { getOverviewGridView, setOverviewGridView } from '../services/config.ts'
import { categoryName, categoryMimes, ALL_OFFICE_MIMES } from '../utils/fileCategories.ts'
import { validateFilename } from '../utils/validateFilename.ts'
import { filterFiles } from '../utils/fileFilters.ts'
import type { Filter } from '../utils/fileFilters.ts'
import type { TemplateCreator, TemplateFile, CreatedFile, OcsErrorResponse } from '../services/templates.ts'
import type { Node } from '@nextcloud/files'

type ViewMode = 'list' | 'grid'

const currentUid = getCurrentUser()?.uid ?? null

const creators = ref<TemplateCreator[]>([])
const activeCreator = ref<TemplateCreator | null>(null)
const allFiles = ref<Node[]>([])
const resultsTruncated = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const viewMode = ref<ViewMode>(getOverviewGridView() ? 'grid' : 'list')
const activeFilter = ref<Filter>('mine')
const searchQuery = ref('')
const showCreateDialog = ref(false)
const newFileName = ref('')
const pendingCreator = ref<TemplateCreator | null>(null)
const pendingTemplate = ref<TemplateFile | null>(null)
const creating = ref(false)
const createError = ref('')
const createInput = ref<InstanceType<typeof NcTextField> | null>(null)

watch(activeCreator, () => {
	searchQuery.value = ''
	// activeFilter is intentionally kept: a user who prefers "Shared with me" should
	// stay on that filter when they switch between document categories.
})

const searchLabel = computed(() =>
	activeCreator.value
		? t('office', 'Search {category}', { category: categoryName(activeCreator.value) })
		: t('office', 'Search'),
)

const filteredFiles = computed(() => {
	if (!activeCreator.value) return []

	return filterFiles(allFiles.value, {
		activeFilter: activeFilter.value,
		currentUid,
		searchQuery: searchQuery.value,
		category: categoryMimes(activeCreator.value),
	})
})

const files = computed(() => filteredFiles.value.slice(0, MAX_DISPLAY_FILES))

// Two ways the list can be incomplete: too many matches to render, or the server
// search itself was capped and older files were never fetched. Both need the
// "Show all in Files" escape hatch, otherwise the page implies it's showing
// everything when it isn't.
const hasMoreFiles = computed(() =>
	filteredFiles.value.length > MAX_DISPLAY_FILES || resultsTruncated.value,
)

const activeCategoryName = computed(() =>
	activeCreator.value ? categoryName(activeCreator.value) : '',
)

function setCreator(creator: TemplateCreator) {
	activeCreator.value = creator
}

function toggleViewMode() {
	const mode: ViewMode = viewMode.value === 'list' ? 'grid' : 'list'
	viewMode.value = mode
	setOverviewGridView(mode === 'grid')
}

// Provided by PageController::index() — set to the editor open URL when a WOPI
// backend is active, null otherwise.
const editorUrl = loadState<string | null>('office', 'editor-url', null)

function openFile(file: Node) {
	if (editorUrl) {
		// WOPI editor active: navigate directly so history.back() returns here.
		window.location.href = editorUrl + '?fileId=' + encodeURIComponent(String(file.fileid))
	} else {
		// No WOPI backend: hand off to NC's file routing. The Files app will
		// trigger whichever default file action is registered (e.g. eurooffice).
		// Note: returning to the overview after close depends on the editor app.
		window.location.href = generateUrl('/f/{fileid}', { fileid: file.fileid })
	}
}

function openInFiles() {
	if (searchQuery.value) {
		window.location.href = generateUrl('/apps/files/search') + '?query=' + encodeURIComponent(searchQuery.value)
	} else {
		window.location.href = generateUrl('/apps/files/recent')
	}
}

function onTemplateSelect(creator: TemplateCreator, template: TemplateFile | null) {
	pendingCreator.value = creator
	pendingTemplate.value = template
	newFileName.value = creator.label + creator.extension
	createError.value = ''
	showCreateDialog.value = true
	nextTick(() => {
		const component = createInput.value as { focus?: () => void; $el?: HTMLElement } | null
		if (typeof component?.focus === 'function') {
			component.focus()
		} else {
			component?.$el?.querySelector<HTMLInputElement>('input')?.focus()
		}
		// setSelectionRange pre-selects the basename (without extension) for quick editing.
		component?.$el?.querySelector<HTMLInputElement>('input')
			?.setSelectionRange(0, newFileName.value.length - creator.extension.length)
	})
}

async function doCreateFromTemplate() {
	if (creating.value) return
	const validationError = validateFilename(newFileName.value)
	if (validationError) {
		createError.value = validationError
		return
	}
	creating.value = true
	createError.value = ''
	try {
		const filePath = '/' + newFileName.value.trim()
		const templatePath = pendingTemplate.value?.templateId ?? ''
		const templateType = pendingTemplate.value?.templateType ?? 'user_system'
		const newFile: CreatedFile = await createFromTemplate(filePath, templatePath, templateType)
		showCreateDialog.value = false
		invalidateOfficeFilesCache()
		window.location.href = generateUrl('/f/{fileid}', { fileid: newFile.fileid })
	} catch (e: unknown) {
		const axiosError = e as OcsErrorResponse
		createError.value = axiosError.response?.data?.ocs?.meta?.message
			?? t('office', 'Failed to create file')
	} finally {
		creating.value = false
	}
}

async function fetchAll() {
	loading.value = true
	error.value = null
	try {
		creators.value = await getTemplates()
		activeCreator.value = creators.value[0] ?? null

		if (creators.value.length > 0) {
			// Union our full static set (ODF + OOXML) with whatever the creators
			// actually advertise, so we never drop a mime the server supports.
			const allMimes = [...new Set([
				...ALL_OFFICE_MIMES,
				...creators.value.flatMap(c => c.mimetypes),
			])]
			const result = await getAllOfficeFiles(allMimes)
			allFiles.value = result.nodes
			resultsTruncated.value = result.truncated
		}
	} catch {
		error.value = t('office', 'Failed to load files')
		allFiles.value = []
		resultsTruncated.value = false
	} finally {
		loading.value = false
	}
}

// Called at module evaluation so the data request is in-flight before Vue
// mounts and paints — reduces perceived time-to-interactive.
fetchAll()
</script>

<template>
	<NcContent app-name="office">
		<NcAppNavigation>
			<template #search>
				<NcAppNavigationSearch v-model="searchQuery" :label="searchLabel" />
			</template>
			<template #list>
				<NcAppNavigationItem v-for="creator in creators"
					:key="creator.app + '-' + creator.extension"
					:name="categoryName(creator)"
					:active="activeCreator === creator"
					@click="setCreator(creator)">
					<template #icon>
						<NcIconSvgWrapper :svg="creator.iconSvgInline ?? ''"
							class="office-overview__nav-icon" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent class="office-overview__content">
			<NcLoadingIcon v-if="loading" class="office-overview__loading" />

			<template v-else>
				<NcEmptyContent v-if="creators.length === 0"
					:name="t('office', 'No office suite installed')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="48" />
					</template>
				</NcEmptyContent>

				<template v-else>
					<TemplateSection v-if="!searchQuery && activeCreator"
						:creator="activeCreator"
						@select="onTemplateSelect" />

					<NcEmptyContent v-if="error"
						:name="error" />

					<section v-else-if="activeCreator" class="office-overview__files" aria-labelledby="files-section-heading">
						<div role="status" class="sr-only">
							{{ t('office', '{count} found in {category}', { count: files.length, category: activeCategoryName }) }}
						</div>

						<div class="office-overview__files-header">
							<h2 id="files-section-heading" class="office-overview__files-title">
								{{ t('office', 'Recent {category}', { category: activeCategoryName }) }}
							</h2>
						</div>

						<div class="office-overview__controls">
							<div class="office-overview__filters"
								role="group"
								:aria-label="t('office', 'Filter files')">
								<NcButton size="small"
									:variant="activeFilter === 'all' ? 'primary' : 'secondary'"
									:aria-pressed="activeFilter === 'all'"
									@click="activeFilter = 'all'">
									{{ t('office', 'All') }}
								</NcButton>
								<NcButton size="small"
									:variant="activeFilter === 'mine' ? 'primary' : 'secondary'"
									:aria-pressed="activeFilter === 'mine'"
									@click="activeFilter = 'mine'">
									{{ t('office', 'Mine') }}
								</NcButton>
								<NcButton size="small"
									:variant="activeFilter === 'shared' ? 'primary' : 'secondary'"
									:aria-pressed="activeFilter === 'shared'"
									@click="activeFilter = 'shared'">
									{{ t('office', 'Shared with me') }}
								</NcButton>
							</div>

							<NcButton :aria-label="viewMode === 'list' ? t('office', 'Switch to grid view') : t('office', 'Switch to list view')"
								variant="tertiary"
								@click="toggleViewMode">
								<template #icon>
									<NcIconSvgWrapper v-if="viewMode === 'list'" :path="mdiViewGrid" :size="20" />
									<NcIconSvgWrapper v-else :path="mdiViewList" :size="20" />
								</template>
							</NcButton>
						</div>

						<NcEmptyContent v-if="files.length === 0"
							:name="t('office', 'No {category} found', { category: activeCategoryName })">
							<template #icon>
								<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="48" />
							</template>
							<template v-if="activeFilter !== 'all'" #description>
								{{ t('office', 'Switch to "All" to see every file you have access to') }}
							</template>
						</NcEmptyContent>

						<div v-else-if="viewMode === 'grid'" class="office-overview__grid">
							<FileCard v-for="file in files"
								:key="file.fileid"
								@click="openFile(file)">
								<template #preview>
									<FilePreview :file="file" :alt="file.basename" />
								</template>

								<template #overlay>
									<ShareIndicator :file="file" :current-uid="currentUid" />
								</template>

								<template #icon>
									<NcIconSvgWrapper :svg="activeCreator.iconSvgInline ?? ''" :size="20" />
								</template>

								<template #name>
									{{ file.basename }}
								</template>

								<template #subname>
									<NcDateTime :timestamp="file.mtime" />
								</template>
							</FileCard>
						</div>

						<div v-else class="office-overview__list">
							<NcListItem v-for="file in files"
								:key="file.fileid"
								:name="file.basename"
								:active="false"
								@click="openFile(file)">
								<template #icon>
									<!-- Requested size is 2x the rendered box for crisp hidpi rendering. -->
									<FilePreview :file="file"
										:size="96"
										:fallback-icon-size="32"
										class="office-overview__list-thumb" />
								</template>
								<template #indicator>
									<span class="office-overview__indicators">
										<NcIconSvgWrapper v-if="file.attributes?.favorite === 1"
											:path="mdiStar"
											:size="16"
											class="office-overview__favourite-icon" />
										<ShareIndicator :file="file" :current-uid="currentUid" :size="16" />
									</span>
								</template>
								<template #subname>
									<NcDateTime :timestamp="file.mtime" />
								</template>
							</NcListItem>
						</div>

						<div v-if="hasMoreFiles" class="office-overview__more">
							<NcButton variant="tertiary" @click="openInFiles">
								{{ searchQuery ? t('office', 'Search all in Files') : t('office', 'Show all in Files') }}
								<template #icon>
									<NcIconSvgWrapper :path="mdiOpenInNew" :size="20" />
								</template>
							</NcButton>
						</div>
					</section>
				</template>

				<!-- Create from template dialog -->
				<NcDialog v-if="showCreateDialog"
					:name="pendingCreator ? pendingCreator.label : ''"
					:open="showCreateDialog"
					close-on-click-outside
					@update:open="showCreateDialog = false">
					<template #actions>
						<NcButton :disabled="creating || !newFileName.trim()" variant="primary" @click="doCreateFromTemplate">
							{{ t('office', 'Create') }}
						</NcButton>
					</template>
					<form class="office-overview__create-form" @submit.prevent="doCreateFromTemplate">
						<NcTextField ref="createInput"
							v-model="newFileName"
							:label="t('office', 'Filename')"
							:error="!!createError"
							:helper-text="createError"
							:disabled="creating" />
					</form>
				</NcDialog>
			</template>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.office-overview__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.office-overview__content {
	/* Safe area so content never sits under the app navigation toggle. */
	padding-top: var(--default-clickable-area);
}

/* NcAppNavigationSearch always renders its clear button; hide it while the
   field is empty (input showing its placeholder).
   TODO: fix this in the NcAppNavigationSearch component itself. */
:deep(.app-navigation-search .input-field__input:placeholder-shown ~ .input-field__trailing-button) {
	display: none;
}

.office-overview__loading {
	margin: 32px auto;
}

.office-overview__files-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
}

.office-overview__files-title {
	margin: 0;
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
}

.office-overview__controls {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: 0 calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
}

.office-overview__filters {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 1);

	:deep(.button-vue) {
		--button-radius: var(--border-radius-pill, 100px);
	}
}

.office-overview__list {
	padding: 0 calc(var(--default-grid-baseline) * 2);
}

.office-overview__more {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
}

.office-overview__list-thumb {
	width: var(--default-clickable-area);
	height: var(--default-clickable-area);
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
	flex-shrink: 0;
}

.office-overview__indicators {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1);
}

.office-overview__favourite-icon {
	color: var(--color-warning);
}

.office-overview__create-form {
	min-height: calc(2 * var(--default-clickable-area));
}

.office-overview__nav-icon {
	display: flex;
	width: 20px;
	height: 20px;

	:deep(svg) {
		width: 100%;
		height: 100%;
	}
}

.sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}
</style>
