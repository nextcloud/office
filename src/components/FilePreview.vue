<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Node } from '@nextcloud/files'

import { mdiFileDocumentOutline } from '@mdi/js'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

const props = withDefaults(defineProps<{
	file: Node
	size?: number
	fallbackIconSize?: number
	alt?: string
}>(), {
	size: 300,
	fallbackIconSize: 48,
	alt: '',
})

// Own state per instance (one per file per view) rather than a single map
// shared across grid/list — a failure at one requested size no longer
// suppresses a different size's thumbnail for the same file.
const failed = ref(false)

const previewUrl = computed(() => {
	const etag = (props.file.attributes?.etag as string | undefined ?? '').slice(0, 6)
	return generateUrl('/core/preview?fileId={fileid}&x={x}&y={y}&v={v}&a=1&mimeFallback=true', {
		fileid: props.file.fileid,
		x: props.size,
		y: props.size,
		v: etag,
	})
})
</script>

<template>
	<div class="file-preview">
		<img
			v-if="!failed"
			:src="previewUrl"
			:alt="alt"
			loading="lazy"
			class="file-preview__image"
			@error="failed = true">
		<NcIconSvgWrapper
			v-else
			:path="mdiFileDocumentOutline"
			:size="fallbackIconSize"
			class="file-preview__fallback" />
	</div>
</template>

<style scoped>
.file-preview {
	display: flex;
	width: 100%;
	height: 100%;
}

.file-preview__image {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.file-preview__fallback {
	margin: auto;
	color: var(--color-text-maxcontrast);
}
</style>
