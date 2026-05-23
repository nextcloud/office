import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		editor: resolve(join('src', 'editor.ts')),
		'settings-admin': resolve(join('src', 'settings-admin.ts')),
		'file-actions': resolve(join('src', 'file-actions.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
