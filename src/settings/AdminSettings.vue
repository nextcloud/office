<script setup lang="ts">
import axios, { isAxiosError } from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

interface AdminData {
	wopi_url: string
	public_wopi_url: string
	callback_url: string
	disable_certificate_verification: string
}

const initial: AdminData = JSON.parse(
	document.getElementById('office-admin-data')?.textContent ?? '{}',
)

const wopiUrl = ref(initial.wopi_url ?? '')
const publicWopiUrl = ref(initial.public_wopi_url ?? '')
const callbackUrl = ref(initial.callback_url ?? '')
const disableCertVerification = ref(initial.disable_certificate_verification === 'yes')
const saving = ref(false)
const error = ref('')
const success = ref(false)

async function save(): Promise<void> {
	saving.value = true
	error.value = ''
	success.value = false

	try {
		await axios.post(generateUrl('/apps/office/settings/admin'), {
			wopi_url: wopiUrl.value,
			public_wopi_url: publicWopiUrl.value,
			callback_url: callbackUrl.value,
			disable_certificate_verification: disableCertVerification.value ? 'yes' : 'no',
		})

		success.value = true
	} catch (e) {
		error.value = isAxiosError(e) && typeof e.response?.data?.error === 'string'
			? e.response.data.error
			: (e as Error).message
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
			:helper-text="t('office', 'Address Nextcloud uses to reach the editor server')"
			type="url" />

		<NcInputField
			v-model="publicWopiUrl"
			:label="t('office', 'Editor server URL for browsers')"
			:placeholder="t('office', 'https://editor.example.com')"
			:helper-text="t('office', 'Only needed if browsers reach the editor at a different address than Nextcloud does')"
			type="url" />

		<NcInputField
			v-model="callbackUrl"
			:label="t('office', 'Nextcloud URL for editor callbacks')"
			:placeholder="t('office', 'https://cloud.example.com')"
			:helper-text="t('office', 'Only needed if the editor server reaches Nextcloud at a different address than browsers do')"
			type="url" />

		<NcCheckboxRadioSwitch v-model="disableCertVerification">
			{{ t('office', 'Disable TLS certificate verification (development only)') }}
		</NcCheckboxRadioSwitch>

		<NcButton
			:disabled="saving"
			variant="primary"
			@click="save">
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
