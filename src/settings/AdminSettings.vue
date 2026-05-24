<script setup lang="ts">
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

interface AdminData {
	wopi_url: string
	disable_certificate_verification: string
}

const initial: AdminData = JSON.parse(
	document.getElementById('office-admin-data')?.textContent ?? '{}'
)

const wopiUrl = ref(initial.wopi_url ?? '')
const disableCertVerification = ref(initial.disable_certificate_verification === 'yes')
const saving = ref(false)
const error = ref('')
const success = ref(false)

async function save(): Promise<void> {
	saving.value = true
	error.value = ''
	success.value = false

	try {
		const response = await fetch('/index.php/apps/office/settings/admin', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'requesttoken': (window as any).OC.requestToken },
			body: JSON.stringify({
				wopi_url: wopiUrl.value,
				disable_certificate_verification: disableCertVerification.value ? 'yes' : 'no',
			}),
		})

		if (!response.ok) {
			throw new Error(`HTTP ${response.status}`)
		}

		success.value = true
	} catch (e) {
		error.value = (e as Error).message
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div class="office-admin-settings">
		<h2>{{ t('office', 'Office') }}</h2>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="success" type="success">
			{{ t('office', 'Settings saved') }}
		</NcNoteCard>

		<NcInputField
			v-model="wopiUrl"
			:label="t('office', 'Editor server URL')"
			:placeholder="t('office', 'https://editor.example.com')"
			type="url"
		/>

		<NcCheckboxRadioSwitch v-model="disableCertVerification">
			{{ t('office', 'Disable TLS certificate verification (development only)') }}
		</NcCheckboxRadioSwitch>

		<NcButton
			:disabled="saving"
			type="primary"
			@click="save"
		>
			{{ t('office', 'Save') }}
		</NcButton>
	</div>
</template>

<style scoped>
.office-admin-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 480px;
}
</style>
