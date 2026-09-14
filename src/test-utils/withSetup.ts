/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'

/**
 * Runs a composable inside a real component instance, so `onMounted`/
 * `onUnmounted` fire for real. Vue's own lifecycle hooks throw outside an
 * active component instance — calling a composable bare in a test loses
 * mount/unmount behaviour entirely, silently for hooks that degrade
 * gracefully (VueUse's own `tryOnMounted`), not so silently for one that
 * calls `onMounted`/`onUnmounted` directly.
 *
 * @param composable the composable to run inside a mounted component
 */
export function withSetup<T>(composable: () => T): { result: T, unmount: () => void } {
	let result!: T
	const app = createApp({
		setup() {
			result = composable()
			return () => null
		},
	})
	app.mount(document.createElement('div'))
	return { result, unmount: () => app.unmount() }
}
