<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'
import { Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFolderOutline } from '@mdi/js'
import type { INode } from '@nextcloud/files'

const props = withDefaults(defineProps<{
	/** Target folder, relative to the user's Files root ("/" is the root). */
	path: string
	disabled?: boolean
}>(), {
	disabled: false,
})

const emit = defineEmits<{
	'update:path': [path: string]
	/** The picker could not be opened, e.g. its code failed to download. */
	error: []
}>()

// Folder names are user data and go into labels that are rendered as text
// (aria-label, the picker's button label) — never as HTML. translate() would
// otherwise escape and sanitize them: "R&D" would read "R&amp;D" and a name
// like "<Drafts>" would vanish.
const asText = { escape: false, sanitize: false }

// The full path, not just the last segment: "Reports" alone does not tell
// apart "/Reports" and "/Team folder/Reports".
const folderLabel = computed(() =>
	props.path === '/' ? t('office', 'All files') : props.path.replace(/^\//, ''),
)

const changeLabel = computed(() =>
	t('office', 'Change folder, currently {folder}', { folder: folderLabel.value }, asText),
)

// The root folder's display name is the user id; call it "All files" like the
// picker's own navigation does.
function displayName(node: INode): string {
	return node.path === '/' ? t('office', 'All files') : node.displayname
}

// Only folders the user may create files in can be chosen — this covers team
// folders and shares, whose permissions come with the node.
function canCreateIn(node: INode): boolean {
	return (node.permissions & Permission.CREATE) !== 0
}

async function pickFolder() {
	// Loaded on demand: @nextcloud/dialogs pulls in the whole file picker and
	// its date locales, several hundred KB the overview never needs unless the
	// user actually changes the folder. Its stylesheet comes with it rather than
	// relying on the one the server happens to load.
	let dialogs: typeof import('@nextcloud/dialogs')
	try {
		[dialogs] = await Promise.all([
			import('@nextcloud/dialogs'),
			import('@nextcloud/dialogs/style.css'),
		])
	} catch {
		emit('error')
		return
	}
	const { FilePickerClosed, getFilePickerBuilder } = dialogs
	const picker = getFilePickerBuilder(t('office', 'Choose a folder'))
		.setMultiSelect(false)
		.allowDirectories(true)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.setCanPick(canCreateIn)
		.startAt(props.path)
		// With nothing selected the picker offers the folder being browsed, as
		// long as canPick allows it; nodes is empty for a read-only folder.
		.setButtonFactory((nodes: INode[]) => [{
			label: nodes.length > 0
				? t('office', 'Choose {folder}', { folder: displayName(nodes[0]) }, asText)
				: t('office', 'Choose'),
			variant: 'primary',
			disabled: nodes.length === 0,
			callback: () => {},
		}])
		.build()

	try {
		const [folder] = await picker.pickNodes()
		emit('update:path', folder.path)
	} catch (error) {
		// Closing the picker without choosing keeps the current folder; any
		// other failure is reported instead of vanishing as a rejection.
		if (!(error instanceof FilePickerClosed)) {
			emit('error')
		}
	}
}
</script>

<template>
	<div class="target-folder-picker">
		<NcIconSvgWrapper :path="mdiFolderOutline" :size="20" />
		<span class="target-folder-picker__label">
			{{ t('office', 'Save in') }}
			<strong class="target-folder-picker__path" aria-live="polite" :title="folderLabel">{{ folderLabel }}</strong>
		</span>
		<NcButton variant="tertiary"
			:disabled="disabled"
			:aria-label="changeLabel"
			@click="pickFolder">
			{{ t('office', 'Change') }}
		</NcButton>
	</div>
</template>

<style scoped>
.target-folder-picker {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin-block-start: calc(2 * var(--default-grid-baseline));
}

.target-folder-picker__label {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
