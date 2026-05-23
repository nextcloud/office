/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare global {
	interface Window {
		OCA?: {
			Viewer?: {
				open(options: { path: string }): void
			}
		}
	}
}

export {}
