import { vi } from 'vitest'

/**
 * Shared stubs for Nextcloud runtime globals that only exist inside a
 * running Nextcloud page. Any spec — including future WOPI-phase-5 specs —
 * gets these for free via `test.setupFiles`, rather than re-stubbing per file.
 */

function substitutePlaceholders(text: string, vars?: Record<string, unknown>): string {
	return text.replace(/{([^{}]*)}/g, (match, key) => {
		const value = vars?.[key]
		return value !== undefined ? encodeURIComponent(String(value)) : match
	})
}

vi.mock('@nextcloud/l10n', () => ({
	translate: (app: string, text: string, placeholders?: Record<string, unknown>) =>
		text.replace(/{([^{}]*)}/g, (match: string, key: string) => {
			const value = placeholders?.[key]
			return value !== undefined ? String(value) : match
		}),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: vi.fn(() => null),
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: vi.fn(() => undefined),
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url: string, params?: Record<string, unknown>) => substitutePlaceholders(url, params)),
	generateOcsUrl: vi.fn((url: string, params?: Record<string, unknown>) => substitutePlaceholders(url, params)),
}))

class ResizeObserverStub {

	observe() {}
	unobserve() {}
	disconnect() {}

}

globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver
