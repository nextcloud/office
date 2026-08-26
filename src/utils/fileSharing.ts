/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node } from '@nextcloud/files'

/**
 * Share-type numbers the current user has attached to a node (outgoing shares),
 * normalised to a flat array. The DAV `oc:share-types` property comes back
 * nested — `{ 'share-type': 3 }` for one share, `{ 'share-type': [3, 1] }` for
 * several — so flatten its values the way the Files app does. Absent/empty ⇒ [].
 *
 * @param file the node to inspect
 */
export function outgoingShareTypes(file: Node): number[] {
	const raw = file.attributes?.['share-types'] as Record<string, unknown> | unknown[] | undefined
	return Object.values(raw ?? {}).flat() as number[]
}

/**
 * Whether a file is shared *with* the current user by someone else (incoming),
 * rather than shared out by them. Owner differs from the current user ⇒ it was
 * mounted into their tree by a share. Drives the indicator's wording.
 *
 * @param file the node to inspect
 * @param currentUid uid of the logged-in user, or null when unknown
 */
export function isIncomingShare(file: Node, currentUid: string | null): boolean {
	return Boolean(currentUid && file.owner && file.owner !== currentUid)
}

/**
 * Whether a file should carry a "shared" indicator: either the current user has
 * shared it with someone (outgoing share types present) or it is an incoming
 * share owned by someone else. Mirrors the Files app's sharing-status definition
 * so the indicator means the same thing here as everywhere else in Nextcloud.
 *
 * @param file the node to inspect
 * @param currentUid uid of the logged-in user, or null when unknown
 */
export function isShared(file: Node, currentUid: string | null): boolean {
	return outgoingShareTypes(file).length > 0 || isIncomingShare(file, currentUid)
}
