<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { generateUrl } from '@nextcloud/router'

interface EditorData {
	editorUrl: string
	postMessageOrigin: string
	fileName: string
}

const data: EditorData = JSON.parse(
	document.getElementById('office-editor-data')?.textContent ?? '{}',
)

const iframeRef = ref<HTMLIFrameElement | null>(null)
const formRef = ref<HTMLFormElement | null>(null)
const iframeName = 'office-editor-frame'

// WOPI editors load via POST, not GET - EO's own /hosting/wopi/:documentType/:mode
// only registers a POST handler, so `<iframe src="...">` (always a GET) 404s.
// A hidden auto-submitting form targeting the iframe by name is the standard
// WOPI embedding pattern (also how the WOPI JS sample and richdocuments do it).
// The split is deliberate, not arbitrary: EO's handler reads `wopisrc` from
// req.query but `access_token` from req.body only (wopiClient.js - `req.query
// ['wopisrc']` vs `req.body['access_token']`) - the standard WOPI convention of
// keeping the bearer credential out of the URL (Referer/history/proxy logs)
// while wopisrc, being just a resource locator, stays part of the action URL.
const editorUrlObject = new URL(data.editorUrl)
const accessToken = editorUrlObject.searchParams.get('access_token') ?? ''
editorUrlObject.searchParams.delete('access_token')
const editorAction = editorUrlObject.toString()

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
	formRef.value?.submit()
})

onUnmounted(() => {
	window.removeEventListener('message', handleMessage)
})
</script>

<template>
	<div>
		<form
			ref="formRef"
			:action="editorAction"
			:target="iframeName"
			method="post"
			hidden>
			<input type="hidden" name="access_token" :value="accessToken">
		</form>
		<iframe
			ref="iframeRef"
			:name="iframeName"
			:title="data.fileName"
			allow="clipboard-read; clipboard-write"
			allowfullscreen />
	</div>
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
