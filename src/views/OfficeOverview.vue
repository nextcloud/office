<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import { sortNodes } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
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
import TemplateSection from '../components/TemplateSection.vue'
import { getAllOfficeFiles, filterByMimes, invalidateOfficeFilesCache, MAX_DISPLAY_FILES } from '../services/officeFiles.ts'
import { getTemplates, createFromTemplate } from '../services/templates.ts'
import { getOverviewGridView, setOverviewGridView } from '../services/config.ts'
import type { TemplateCreator, TemplateFile, CreatedFile, OcsErrorResponse } from '../services/templates.ts'
import type { Node } from '@nextcloud/files'

type Filter = 'all' | 'mine' | 'shared'
type ViewMode = 'list' | 'grid'

const MIME_CATEGORIES: Record<string, string> = {
	'application/vnd.oasis.opendocument.text': t('office', 'Documents'),
	'application/vnd.oasis.opendocument.text-template': t('office', 'Documents'),
	'application/msword': t('office', 'Documents'),
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': t('office', 'Documents'),
	'application/vnd.oasis.opendocument.spreadsheet': t('office', 'Spreadsheets'),
	'application/vnd.oasis.opendocument.spreadsheet-template': t('office', 'Spreadsheets'),
	'application/vnd.ms-excel': t('office', 'Spreadsheets'),
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': t('office', 'Spreadsheets'),
	'application/vnd.oasis.opendocument.presentation': t('office', 'Presentations'),
	'application/vnd.oasis.opendocument.presentation-template': t('office', 'Presentations'),
	'application/vnd.ms-powerpoint': t('office', 'Presentations'),
	'application/vnd.openxmlformats-officedocument.presentationml.presentation': t('office', 'Presentations'),
	'application/vnd.oasis.opendocument.graphics': t('office', 'Diagrams'),
	'application/vnd.oasis.opendocument.graphics-template': t('office', 'Diagrams'),
}

const currentUid = getCurrentUser()?.uid ?? null

const creators = ref<TemplateCreator[]>([])
const activeCreator = ref<TemplateCreator | null>(null)
const allFiles = ref<Node[]>([])
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
const failedPreviews = ref<Record<number, boolean>>({})
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

	const byCategory = filterByMimes(allFiles.value, activeCreator.value.mimetypes)

	let filtered = byCategory
	if (activeFilter.value === 'mine') {
		filtered = byCategory.filter(f =>
			f.owner === currentUid
			&& !['group', 'shared'].includes(f.attributes?.['nc:mount-type'] as string),
		)
	} else if (activeFilter.value === 'shared') {
		filtered = byCategory.filter(f => f.attributes?.['nc:mount-type'] === 'shared')
	}

	if (searchQuery.value) {
		const q = searchQuery.value.toLowerCase()
		filtered = filtered.filter(f => f.basename.toLowerCase().includes(q))
	}

	return sortNodes(filtered, {
		sortFavoritesFirst: true,
		sortingMode: 'mtime',
		sortingOrder: 'desc',
	})
})

const files = computed(() => filteredFiles.value.slice(0, MAX_DISPLAY_FILES))
const hasMoreFiles = computed(() => filteredFiles.value.length > MAX_DISPLAY_FILES)

const activeCategoryName = computed(() =>
	activeCreator.value ? categoryName(activeCreator.value) : '',
)

function categoryName(creator: TemplateCreator): string {
	for (const mime of (creator.mimetypes ?? [])) {
		if (MIME_CATEGORIES[mime]) return MIME_CATEGORIES[mime]
	}
	return creator.label
}

function setCreator(creator: TemplateCreator) {
	activeCreator.value = creator
}

function toggleViewMode() {
	const mode: ViewMode = viewMode.value === 'list' ? 'grid' : 'list'
	viewMode.value = mode
	setOverviewGridView(mode === 'grid')
}

function getPreviewUrl(file: Node): string {
	const etag = (file.attributes?.etag as string | undefined ?? '').slice(0, 6)
	return generateUrl('/core/preview?fileId={fileid}&x={x}&y={y}&v={v}&a=1&mimeFallback=true', {
		fileid: file.fileid,
		x: 300,
		y: 300,
		v: etag,
	})
}

function openFile(file: Node) {
	// Navigate directly to the in-app editor. The older approach used the NC
	// Viewer (OCA.Viewer.open) or the Files shortlink (/f/{fileid}), both of
	// which route through the Files app and lose the overview as the referrer.
	// If we ever need to fall back: OCA.Viewer.open({ path: file.path }) or
	// window.location.href = generateUrl('/f/{fileid}', { fileid: file.fileid })
	window.location.href = generateUrl('/apps/office/open') + '?fileId=' + encodeURIComponent(String(file.fileid))
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

function validateFilename(name: string): string | null {
	const trimmed = name.trim()
	if (!trimmed) return t('office', 'Filename cannot be empty')
	if (/[/\\]/.test(trimmed) || trimmed.includes('\x00')) return t('office', 'Filename contains invalid characters')
	return null
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
			const allMimes = creators.value.flatMap(c => c.mimetypes)
			allFiles.value = await getAllOfficeFiles(allMimes)
		}
	} catch {
		error.value = t('office', 'Failed to load files')
		allFiles.value = []
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
						<NcIconSvgWrapper :svg="mdiFileDocumentOutline" :size="48" />
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
							{{ t('office', '{count} {category} found', { count: files.length, category: activeCategoryName }) }}
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
									<NcIconSvgWrapper v-if="viewMode === 'list'" :svg="mdiViewGrid" :size="20" />
									<NcIconSvgWrapper v-else :svg="mdiViewList" :size="20" />
								</template>
							</NcButton>
						</div>

						<NcEmptyContent v-if="files.length === 0"
							:name="t('office', 'No {category} found', { category: activeCategoryName })">
							<template #icon>
								<NcIconSvgWrapper :svg="mdiFileDocumentOutline" :size="48" />
							</template>
							<template v-if="activeFilter !== 'all'" #description>
								{{ t('office', 'Switch to All to see every file you have access to') }}
							</template>
						</NcEmptyContent>

						<div v-else-if="viewMode === 'grid'" class="office-overview__grid">
							<FileCard v-for="file in files"
								:key="file.fileid"
								@click="openFile(file)">
								<template #preview>
									<img v-if="!failedPreviews[file.fileid]"
										:src="getPreviewUrl(file)"
										:alt="file.basename"
										loading="lazy"
										class="overview-file-preview"
										@error="failedPreviews = { ...failedPreviews, [file.fileid]: true }">
									<NcIconSvgWrapper v-else
										:svg="mdiFileDocumentOutline"
										:size="48"
										class="overview-file-icon" />
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
								<template #indicator>
									<NcIconSvgWrapper v-if="file.attributes?.favorite === 1"
										:svg="mdiStar"
										:size="16"
										class="office-overview__favourite-icon" />
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
									<NcIconSvgWrapper :svg="mdiOpenInNew" :size="20" />
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

.overview-file-preview {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.overview-file-icon {
	margin: auto;
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
