<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiShareVariant } from '@mdi/js'
import type { Node } from '@nextcloud/files'
import { isIncomingShare, isShared } from '../utils/fileSharing.ts'

const props = withDefaults(defineProps<{
	file: Node
	currentUid: string | null
	size?: number
}>(), {
	size: 16,
})

const shared = computed(() => isShared(props.file, props.currentUid))

// Incoming shares name the owner; outgoing ones just read "Shared". The label is
// both the tooltip and the accessible name — the icon alone must never carry the
// meaning (accessibility) or the colour (it is only a hint on top of the icon).
const label = computed(() => {
	if (!isIncomingShare(props.file, props.currentUid)) {
		return t('office', 'Shared')
	}
	const owner = props.file.attributes?.['owner-display-name'] as string | undefined
	return owner
		? t('office', 'Shared by {owner}', { owner })
		: t('office', 'Shared')
})
</script>

<template>
	<span v-if="shared"
		class="share-indicator"
		role="img"
		:aria-label="label"
		:title="label">
		<NcIconSvgWrapper :path="mdiShareVariant" :size="size" />
	</span>
</template>

<style scoped>
.share-indicator {
	display: inline-flex;
	color: var(--color-primary-element);
}
</style>
