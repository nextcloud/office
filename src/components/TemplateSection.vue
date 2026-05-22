<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import type { TemplateCreator, TemplateFile } from '../services/templates.ts'

const THEME_PALETTES: Record<string, [string, string, string, string]> = {
	document: ['hsl(203 79% 78%)', 'hsl(203 79% 60%)', 'hsl(203 70% 42%)', 'hsl(203 65% 26%)'],
	spreadsheet: ['hsl(79 46% 70%)', 'hsl(79 46% 52%)', 'hsl(79 50% 38%)', 'hsl(79 55% 24%)'],
	presentation: ['hsl(23 83% 80%)', 'hsl(23 83% 66%)', 'hsl(23 75% 48%)', 'hsl(23 70% 32%)'],
	drawing: ['hsl(47 80% 62%)', 'hsl(47 80% 39%)', 'hsl(47 82% 28%)', 'hsl(47 85% 18%)'],
}

// Inter-card gap (calc(baseline * 3), baseline = 4px) and a representative card
// width, used to size the scroll step so a card of context stays visible.
const CARD_WIDTH = 160
const CARD_GAP = 12

const props = defineProps<{ creator: TemplateCreator }>()
const emit = defineEmits<{ select: [creator: TemplateCreator, template: TemplateFile | null] }>()

const list = ref<HTMLUListElement | null>(null)
const canScrollLeft = ref(false)
const canScrollRight = ref(false)
const failedPreviews = ref<Record<number, boolean>>({})
let resizeObserver: ResizeObserver | null = null

const themeType = computed(() => {
	const mimes = (props.creator.mimetypes ?? []).join(' ')
	if (/presentation|powerpoint/.test(mimes)) return 'presentation'
	if (/spreadsheet|ms-excel/.test(mimes)) return 'spreadsheet'
	if (/graphics|drawing/.test(mimes)) return 'drawing'
	return 'document'
})

const cardStyle = computed(() => {
	const isPresentation = themeType.value === 'presentation'
	const height = isPresentation ? 150 : 200
	const ratio = isPresentation ? 16 / 9 : 2 / 3
	return {
		width: `${Math.round(height * ratio)}px`,
		'--tpl-preview-height': `${height}px`,
	}
})

const sectionStyle = computed(() => {
	const [glow1, glow2, glow3, glow4] = THEME_PALETTES[themeType.value]
	return {
		'--tpl-glow-1': glow1,
		'--tpl-glow-2': glow2,
		'--tpl-glow-3': glow3,
		'--tpl-glow-4': glow4,
	}
})

const canScroll = computed(() => canScrollLeft.value || canScrollRight.value)

const items = computed(() => [{ blank: true as const }, ...(props.creator.templates ?? [])])

watch(() => props.creator, () => {
	// New creator means a different template count — jump back to the start.
	if (list.value) {
		list.value.scrollLeft = 0
	}
	updateArrows()
})

function updateArrows() {
	if (!list.value) return
	const { scrollLeft, scrollWidth, clientWidth } = list.value
	canScrollLeft.value = scrollLeft > 0
	// 1px tolerance absorbs sub-pixel rounding at the far end.
	canScrollRight.value = scrollLeft + clientWidth < scrollWidth - 1
}

function scrollByStep(direction: number) {
	if (!list.value) return
	const step = Math.max(CARD_WIDTH + CARD_GAP, list.value.clientWidth - (CARD_WIDTH + CARD_GAP))
	list.value.scrollBy({ left: direction * step, behavior: 'smooth' })
}

function nameWithoutExt(basename: string) {
	const dot = basename.lastIndexOf('.')
	return dot > 0 ? basename.slice(0, dot) : basename
}

function templatePreviewUrl(template: TemplateFile) {
	if (template.previewUrl) {
		return template.previewUrl
	}
	return generateUrl('/core/preview?fileId={fileid}&x=256&y=256&a=1', { fileid: template.fileid })
}

onMounted(() => {
	resizeObserver = new ResizeObserver(() => updateArrows())
	if (list.value) {
		resizeObserver.observe(list.value)
	}
	updateArrows()
})

onUnmounted(() => {
	resizeObserver?.disconnect()
})
</script>

<template>
	<section class="template-section"
		:style="sectionStyle"
		aria-labelledby="template-section-heading">
		<div class="template-section__header">
			<h2 id="template-section-heading" class="template-section__heading">
				{{ t('office', 'Create new') }}
			</h2>

			<div v-if="canScroll" class="template-section__nav">
				<NcButton variant="tertiary"
					:aria-label="t('office', 'Scroll left')"
					:disabled="!canScrollLeft"
					@click="scrollByStep(-1)">
					<template #icon>
						<NcIconSvgWrapper :svg="mdiChevronLeft" :size="20" />
					</template>
				</NcButton>
				<NcButton variant="tertiary"
					:aria-label="t('office', 'Scroll right')"
					:disabled="!canScrollRight"
					@click="scrollByStep(1)">
					<template #icon>
						<NcIconSvgWrapper :svg="mdiChevronRight" :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<ul ref="list" class="template-section__list" @scroll="updateArrows">
			<li v-for="item in items"
				:key="'blank' in item ? 'blank' : item.fileid"
				class="template-section__item">
				<!-- Blank file card -->
				<button v-if="'blank' in item"
					class="template-card"
					:style="cardStyle"
					@click="emit('select', creator, null)">
					<span class="template-card__preview">
						<NcIconSvgWrapper :svg="creator.iconSvgInline ?? ''" :size="32" />
					</span>
					<span class="template-card__name">{{ t('office', 'Blank') }}</span>
				</button>

				<!-- Template card -->
				<button v-else
					class="template-card"
					:style="cardStyle"
					@click="emit('select', creator, item)">
					<span class="template-card__preview">
						<img v-if="item.hasPreview && !failedPreviews[item.fileid]"
							:src="templatePreviewUrl(item)"
							:alt="nameWithoutExt(item.basename)"
							loading="lazy"
							class="template-card__image"
							@error="failedPreviews = { ...failedPreviews, [item.fileid]: true }">
						<NcIconSvgWrapper v-else :svg="creator.iconSvgInline ?? ''" :size="48" />
					</span>
					<span class="template-card__name">{{ nameWithoutExt(item.basename) }}</span>
				</button>
			</li>
		</ul>
	</section>
</template>

<style scoped>
.template-section {
	padding: calc(var(--default-grid-baseline) * 4) 0;
	margin: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4) 0;
	background:
		radial-gradient(78% 82% at 14% 2%,
			color-mix(in srgb, var(--tpl-glow-1) 28%, transparent), transparent 66%),
		radial-gradient(72% 76% at 102% -2%,
			color-mix(in srgb, var(--tpl-glow-2) 24%, transparent), transparent 64%),
		radial-gradient(82% 88% at 20% 114%,
			color-mix(in srgb, var(--tpl-glow-3) 22%, transparent), transparent 68%),
		radial-gradient(88% 94% at 98% 104%,
			color-mix(in srgb, var(--tpl-glow-4) 26%, transparent), transparent 70%),
		color-mix(in srgb, var(--tpl-glow-2) 8%, var(--color-main-background));
	border-radius: var(--border-radius-large);
}

.template-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding-inline: calc(var(--default-grid-baseline) * 4);
}

.template-section__heading {
	/* !important overrides the global heading top margin from server styles. */
	margin: 0 !important;
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
}

.template-section__nav {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1);
}

.template-section__list {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
	list-style: none;
	padding: calc(var(--default-grid-baseline) * 1) 0 0;
	margin: 0;
	overflow-x: auto;
	/* The arrow buttons are the scroll affordance — hide the native scrollbar. */
	scrollbar-width: none;

	&::-webkit-scrollbar {
		display: none;
	}
}

.template-section__item {
	flex: 0 0 auto;
}

.template-section__item:first-child {
	margin-inline-start: calc(var(--default-grid-baseline) * 4);
}

.template-section__item:last-child {
	margin-inline-end: calc(var(--default-grid-baseline) * 4);
}

.template-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	/* Width is set inline (cardStyle) from the type's aspect ratio. */
	padding: 0;
	border: none;
	background: none;
	cursor: pointer;
	border-radius: var(--border-radius-large);

	&:hover,
	&:focus-visible {
		background: none;
	}

	&:hover .template-card__preview,
	&:focus-visible .template-card__preview {
		/* Inset-style ring replaces the resting border on hover. */
		box-shadow:
			0 0 0 2px var(--color-primary-element),
			0 4px 6px rgba(0, 0, 0, 0.28);
	}
}

.template-card__preview {
	position: relative;
	width: 100%;
	height: var(--tpl-preview-height);
	overflow: hidden;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	box-sizing: border-box;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
	transition: box-shadow var(--animation-quick) ease;
}

.template-card__image {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.template-card__name {
	margin-top: calc(var(--default-grid-baseline) * 2);
	font-size: var(--font-size-small, 13px);
	color: var(--color-main-text);
	text-align: center;
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
