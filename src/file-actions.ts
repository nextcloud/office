/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { DefaultType, Permission, registerFileAction } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { getSharingToken, isPublicShare } from '@nextcloud/sharing/public'

const supportedMimes: string[] = loadState('office', 'supported-mimes', [])

registerFileAction({
	id: 'office-open',

	displayName: () => 'Open in Office',

	iconSvgInline: () => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm4 18H6V4h7v5h5z"/></svg>',

	default: DefaultType.DEFAULT,

	order: 10,

	enabled: ({ nodes }) => {
		if (nodes.length !== 1) {
			return false
		}
		const node = nodes[0]
		if ((node.permissions & Permission.READ) === 0) {
			return false
		}
		return supportedMimes.includes(node.mime ?? '')
	},

	exec: async ({ nodes }) => {
		const node = nodes[0]
		const fileId = node.fileid

		if (fileId === undefined) {
			return false
		}

		if (isPublicShare()) {
			const token = getSharingToken()
			if (!token) {
				return false
			}
			window.location.href = generateUrl('/apps/office/open/share/{token}', { token })
				+ '?fileId=' + encodeURIComponent(String(fileId))
		} else {
			window.location.href = generateUrl('/apps/office/open') + '?fileId=' + encodeURIComponent(String(fileId))
		}

		return true
	},
})
