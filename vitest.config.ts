import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'jsdom',
		setupFiles: ['./vitest.setup.ts'],
		include: ['src/**/*.spec.ts'],
		// @nextcloud/vue components self-import CSS; without this, importing
		// them under Node's ESM loader throws ERR_UNKNOWN_FILE_EXTENSION.
		css: true,
		server: {
			deps: {
				// @nextcloud/vue for CSS handling; @nextcloud/router and @nextcloud/files
				// alongside it so mocking @nextcloud/router applies consistently across
				// the whole graph — leaving it externalized while its consumers are
				// inlined caused @nextcloud/files/dav.mjs to resolve the real
				// @nextcloud/router instead of the mocked one.
				inline: ['@nextcloud/vue', '@nextcloud/router', '@nextcloud/files'],
			},
		},
	},
})
