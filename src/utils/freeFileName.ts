/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getUniqueName } from '@nextcloud/files'

// Upper bound on existence checks for one suggestion. Each check is one small
// request; past "(N)" taken copies the plain default is returned and the
// server's "already exists" error asks the user to rename.
export const MAX_NAME_PROBES = 20

/**
 * Find the first of "Name.ext", "Name (1).ext", "Name (2).ext", … that is not
 * taken, asking `isTaken` one candidate at a time. The candidates come from
 * getUniqueName() so the numbering matches the Files app's "New" menu.
 *
 * Probing instead of listing the folder keeps the work bounded: the cost
 * depends on how many copies of this name exist, not on the folder's size.
 *
 * @param defaultName Name to start from, e.g. "New document.docx"
 * @param isTaken Resolves true when a name already exists in the target folder
 * @param maxProbes Most candidates to check before falling back to defaultName
 */
export async function findFreeFileName(
	defaultName: string,
	isTaken: (name: string) => Promise<boolean>,
	maxProbes = MAX_NAME_PROBES,
): Promise<string> {
	const taken: string[] = []
	for (let probe = 0; probe < maxProbes; probe++) {
		const candidate = getUniqueName(defaultName, taken)
		if (!await isTaken(candidate)) {
			return candidate
		}
		taken.push(candidate)
	}
	return defaultName
}
