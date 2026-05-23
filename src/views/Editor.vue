<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { generateUrl } from '@nextcloud/router'

interface EditorData {
	editorUrl: string
	postMessageOrigin: string
	fileName: string
}

const data: EditorData = JSON.parse(
	document.getElementById('office-editor-data')?.textContent ?? '{}'
)

const iframeRef = ref<HTMLIFrameElement | null>(null)

function handleMessage(event: MessageEvent): void {
	// Reject messages from any origin other than the editor server.
	if (event.origin !== new URL(data.editorUrl).origin) {
		return
	}

	let msg: Record<string, unknown>
	try {
		msg = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
	} catch {
		return
	}

	const id = msg.MessageId as string | undefined

	if (id === 'App_LoadingStatus' && msg.Values) {
		const status = (msg.Values as Record<string, string>).Status
		if (status === 'Document_Loaded') {
			document.title = data.fileName
		}
	}

	if (id === 'UI_Close') {
		// window.close() only works for popup windows; full-page navigation
		// requires history traversal. Fall back to the office overview if there
		// is no history entry to go back to (e.g. direct URL access).
		if (window.history.length > 1) {
			window.history.back()
		} else {
			window.location.href = generateUrl('/apps/office')
		}
	}
}

onMounted(() => {
	window.addEventListener('message', handleMessage)
})

onUnmounted(() => {
	window.removeEventListener('message', handleMessage)
})
</script>

<template>
	<iframe
		ref="iframeRef"
		:src="data.editorUrl"
		:title="data.fileName"
		allow="clipboard-read; clipboard-write"
		allowfullscreen
	/>
</template>

<style scoped>
iframe {
	position: fixed;
	inset: 0;
	width: 100%;
	height: 100%;
	border: none;
}
</style>
